<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\WhatsappChatHelper;
use App\Models\WhatsappBotConfig;
use App\Services\WhatsappBotAiService;
use App\Services\WhatsappBotSendService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WhatsappBotController extends Controller
{
    /**
     * Eventos de estado de entrega que Kapso reenvía desde Meta para un mensaje ya
     * enviado por el bot o por el módulo (Prompt 02, grupo 137).
     *
     * @var array<int, string>
     */
    private const DELIVERY_STATUS_EVENTS = [
        'whatsapp.message.sent',
        'whatsapp.message.delivered',
        'whatsapp.message.read',
        'whatsapp.message.failed',
    ];

    /**
     * Webhook público que Kapso llama al recibir un mensaje de WhatsApp del cliente final,
     * o al reportar un cambio de estado de entrega de un mensaje ya enviado.
     * No requiere autenticación Sanctum (Kapso no manda un token Sanctum); la seguridad
     * la da la firma HMAC verificada en `verify_signature()`.
     *
     * Siempre devuelve 200 a Kapso (salvo firma inválida) para que no reintente indefinidamente;
     * cualquier error de procesamiento interno se loguea pero no cambia la respuesta HTTP.
     */
    public function receive(Request $request): JsonResponse
    {
        $config = WhatsappBotConfig::where('is_active', true)->first();
        if (! $config) {
            return response()->json(['ok' => true], 200);
        }

        if (! $this->verify_signature($request, $config)) {
            Log::channel('daily')->warning('WhatsappBotController: firma inválida.', [
                'ip' => $request->ip(),
            ]);
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $raw_body = $request->getContent();
        $payload  = json_decode($raw_body, true);
        if (! is_array($payload)) {
            return response()->json(['ok' => true], 200);
        }

        $event_type = $this->resolve_event_type($request, $payload);

        // Todo el procesamiento nuevo (persistencia, estados) va protegido: un error acá
        // nunca debe hacer perder el 200 hacia Kapso ni frenar el reintento de otros eventos.
        try {
            if ($event_type === 'whatsapp.message.received') {
                $this->handle_message_received($config, $payload);
            } elseif (in_array($event_type, self::DELIVERY_STATUS_EVENTS, true)) {
                WhatsappChatHelper::handle_delivery_status_event($event_type, $payload);
            }
        } catch (\Throwable $e) {
            Log::channel('daily')->error('WhatsappBotController: excepción no controlada procesando el webhook.', [
                'event' => $event_type,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json(['ok' => true], 200);
    }

    /**
     * Procesa un mensaje entrante: lo persiste (chat + mensaje), y si corresponde,
     * genera y envía la respuesta de IA, persistiéndola también.
     *
     * @param  WhatsappBotConfig  $config
     * @param  array  $payload  Payload completo del webhook.
     * @return void
     */
    private function handle_message_received(WhatsappBotConfig $config, array $payload): void
    {
        $parsed = $this->parse_inbound_message($payload);
        if ($parsed === null || trim((string) ($parsed['body'] ?? '')) === '') {
            return;
        }

        $user_id = (int) $config->user_id;
        $from    = $parsed['from'];
        $body    = $parsed['body'];

        Log::channel('daily')->info('WhatsappBotController: mensaje entrante.', [
            'from'    => $from,
            'type'    => $parsed['type'],
            'body'    => mb_substr((string) $body, 0, 120),
            'user_id' => $user_id,
        ]);

        // Persiste el chat (con auto-vinculación de cliente si corresponde) y el mensaje 'in'.
        $chat = WhatsappChatHelper::store_inbound_message($user_id, $from, (string) $body, $payload, $config);

        // La IA solo responde si el bot está activo a nivel empresa Y el chat puntual no la tiene apagada.
        if (! $config->is_active || ! $chat->ai_enabled) {
            return;
        }

        $ai_service   = new WhatsappBotAiService();
        $ai_response  = $ai_service->generate_response((string) $body, $user_id, $config);

        if ($ai_response !== '') {
            $send_service   = new WhatsappBotSendService();
            $wa_message_id  = $send_service->send_text($from, $ai_response, $config);

            WhatsappChatHelper::store_outbound_ai_message($chat, $ai_response, $wa_message_id);

            Log::channel('daily')->info('WhatsappBotController: respuesta enviada.', [
                'to'       => $from,
                'response' => mb_substr($ai_response, 0, 120),
            ]);
        } else {
            Log::channel('daily')->warning('WhatsappBotController: respuesta IA vacía, no se envió mensaje.', [
                'from'    => $from,
                'user_id' => $user_id,
            ]);
        }
    }

    /**
     * Devuelve la configuración del bot para el usuario autenticado.
     */
    public function get_config(): JsonResponse
    {
        $config = WhatsappBotConfig::where('user_id', $this->userId())->first();
        return response()->json(['model' => $config], 200);
    }

    /**
     * Crea o actualiza la configuración del bot para el usuario autenticado.
     */
    public function update_config(Request $request): JsonResponse
    {
        $config = WhatsappBotConfig::updateOrCreate(
            ['user_id' => $this->userId()],
            [
                'kapso_api_key'   => $request->kapso_api_key,
                'phone_number_id' => $request->phone_number_id,
                'webhook_secret'  => $request->webhook_secret,
                'is_active'       => (bool) $request->is_active,
            ]
        );

        return response()->json(['model' => $config], 200);
    }

    /**
     * Verifica la firma HMAC-SHA256 del cuerpo del request contra el webhook_secret.
     */
    private function verify_signature(Request $request, WhatsappBotConfig $config): bool
    {
        $signature = (string) ($request->header('X-Kapso-Signature') ?: $request->header('X-Webhook-Signature'));
        if ($signature === '') {
            return false;
        }

        $signature = str_replace('sha256=', '', $signature);
        $raw_body  = $request->getContent();
        $expected  = hash_hmac('sha256', $raw_body, (string) $config->webhook_secret);

        return hash_equals($expected, $signature);
    }

    /**
     * Resuelve el tipo de evento desde el header o el payload.
     */
    private function resolve_event_type(Request $request, array $payload): ?string
    {
        $header_event = $request->header('X-Webhook-Event');
        if ($header_event !== null && $header_event !== '') {
            return (string) $header_event;
        }

        if (isset($payload['event']) && is_string($payload['event'])) {
            return $payload['event'];
        }

        if (isset($payload['message']) && is_array($payload['message'])) {
            return 'whatsapp.message.received';
        }

        return null;
    }

    /**
     * Extrae from, type y body del payload Kapso para mensajes entrantes.
     * Solo procesa text y audio; ignora imagen, documento, etc.
     *
     * @return array{from: string, type: string, body: string|null}|null
     */
    private function parse_inbound_message(array $payload): ?array
    {
        $message = $payload['message'] ?? null;
        if (! is_array($message)) {
            return null;
        }

        $from = $message['from'] ?? null;
        if (($from === null || $from === '') && isset($payload['conversation']['phone_number'])) {
            $from = $payload['conversation']['phone_number'];
        }

        if ($from === null || $from === '') {
            return null;
        }

        $raw_type = isset($message['type']) ? strtolower((string) $message['type']) : 'text';

        // Solo procesamos text y audio
        if (! in_array($raw_type, ['text', 'audio', 'ptt', 'voice'], true)) {
            return null;
        }

        $body = null;
        if ($raw_type === 'text') {
            $body = isset($message['text']['body']) ? trim((string) $message['text']['body']) : null;
        } else {
            // audio / ptt / voice: transcripción Kapso
            if (isset($message['kapso']['transcript']['text'])) {
                $transcript = trim((string) $message['kapso']['transcript']['text']);
                $body = $transcript !== '' ? $transcript : '[Audio sin transcripción]';
            } else {
                $body = '[Audio sin transcripción]';
            }
        }

        return [
            'from' => (string) $from,
            'type' => $raw_type,
            'body' => $body,
        ];
    }
}
