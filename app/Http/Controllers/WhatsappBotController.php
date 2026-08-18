<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\WhatsappChatHelper;
use App\Http\Controllers\Helpers\WhatsappPhoneHelper;
use App\Models\WhatsappBotConfig;
use App\Models\WhatsappChat;
use App\Services\WhatsappAgentScheduler;
use App\Services\WhatsappInboundMediaService;
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
     * Procesa un mensaje entrante del webhook: lo parsea y delega en `process_inbound()`.
     *
     * 🔴 EL CORTE POR CUERPO VACÍO MIRA TAMBIÉN SI HAY ADJUNTO, y ese "y" es la mitad del
     * arreglo de la misión whatsapp-sidebar-multimedia. Hasta acá el guard era
     * `body === ''  →  return`, así que una imagen SIN epígrafe moría en esta línea aunque
     * `parse_inbound_message()` la hubiera dejado pasar: son dos guards distintos y hay que
     * tocar los dos. El síntoma no era un error sino silencio — la foto no aparecía en la
     * conversación y, peor, no se abría la ventana de 24 h, así que el operador no le podía
     * contestar al cliente que le acababa de mandar una foto (D14).
     *
     * @param  WhatsappBotConfig  $config
     * @param  array  $payload  Payload completo del webhook.
     * @return void
     */
    private function handle_message_received(WhatsappBotConfig $config, array $payload): void
    {
        $parsed = $this->parse_inbound_message($payload);
        if ($parsed === null) {
            return;
        }

        $body  = trim((string) ($parsed['body'] ?? ''));
        $media = (isset($parsed['media']) && is_array($parsed['media'])) ? $parsed['media'] : null;

        // Solo se descarta cuando no hay NADA: ni texto ni adjunto.
        if ($body === '' && is_null($media)) {
            return;
        }

        $this->process_inbound($config, $parsed['from'], $body, $payload, false, $media);
    }

    /**
     * Persiste un mensaje entrante (chat + mensaje 'in') y programa la respuesta del agente.
     *
     * Es público y está extraído a propósito: lo usan el webhook real de Kapso y el endpoint
     * de simulación (`simulate_inbound`), y el punto de la simulación es justamente que
     * recorra EXACTAMENTE el mismo camino — misma ventana de 24 h, mismo debounce, mismo agente.
     *
     * 🔴 Acá ya NO se genera ni se envía la respuesta de IA. Hasta la misión whatsapp-agente,
     * este método llamaba a Anthropic y a Kapso de forma sincrónica adentro del request del
     * webhook: Kapso quedaba esperando varios segundos por una llamada a un tercero. Ahora eso
     * vive en `GenerateWhatsappAiReplyJob`, y `WhatsappAgentScheduler` decide si corre después
     * de la respuesta HTTP (demora 0) o diferido en la cola (demora configurada). No volver a
     * meterlo inline acá.
     *
     * @param  WhatsappBotConfig  $config  Config del dueño al que pertenece el chat.
     * @param  string  $from  Teléfono del cliente, sin normalizar.
     * @param  string  $body  Texto (o transcripción del audio) del mensaje entrante.
     * @param  array  $payload  Payload completo, para extraer el nombre de contacto.
     * @param  bool  $is_simulated  True solo cuando el mensaje vino de `simulate_inbound()`.
     *                              Es lo ÚNICO que diferencia los dos caminos: se propaga a la
     *                              fila del mensaje y al chat, y de ahí lo lee el freno de
     *                              `WhatsappBotSendService` para no salir a Kapso.
     * @param  array|null  $media  Descriptor del adjunto que armó `parse_inbound_message()`, o
     *                             null si el mensaje es de texto. Va ÚLTIMO y con default a
     *                             propósito: `simulate_inbound()` llama con cinco argumentos
     *                             posicionales y PHP 7.4 no tiene argumentos nombrados, así que
     *                             cualquier otra posición obligaría a tocar ese caller.
     * @return void
     */
    public function process_inbound(WhatsappBotConfig $config, $from, $body, array $payload, $is_simulated = false, $media = null): void
    {
        $user_id = (int) $config->user_id;

        Log::channel('daily')->info('WhatsappBotController: mensaje entrante.', [
            'from'      => $from,
            'type'      => isset($payload['message']['type']) ? strtolower((string) $payload['message']['type']) : 'text',
            'body'      => mb_substr((string) $body, 0, 120),
            'media'     => (isset($media['media_type']) ? (string) $media['media_type'] : null),
            'user_id'   => $user_id,
            'simulado'  => (bool) $is_simulated,
        ]);

        // Persiste el chat (con auto-vinculación de cliente si corresponde) y el mensaje 'in'.
        $chat = WhatsappChatHelper::store_inbound_message($user_id, $from, (string) $body, $payload, $config, (bool) $is_simulated, $media);

        // El scheduler aplica el debounce configurado: descarta lo que quedó pendiente de
        // confirmación, bumpea el token y encola el job que genera la respuesta.
        (new WhatsappAgentScheduler())->schedule_after_inbound($chat, $config);
    }

    /**
     * Devuelve la configuración del bot para el usuario autenticado.
     *
     * Solo el dueño: el modelo trae `kapso_api_key` y `webhook_secret` en texto plano, y hasta
     * la misión whatsapp-agente cualquier empleado con token Sanctum los podía leer.
     */
    public function get_config(): JsonResponse
    {
        if (! $this->is_owner()) {
            return response()->json(['message' => 'Solo el dueño puede ver la configuración de WhatsApp.'], 403);
        }

        $config = WhatsappBotConfig::where('user_id', $this->userId())->first();
        return response()->json(['model' => $config], 200);
    }

    /**
     * Crea o actualiza la configuración del bot para el usuario autenticado.
     *
     * Los campos técnicos (kapso_api_key, phone_number_id, webhook_secret, is_active) se
     * cargan desde ABM → Integraciones (equipo de ComercioCity). Los campos de
     * configuración funcional del agente (agent_personality, agent_skills,
     * ai_enabled_default, auto_send_sale_pdf, ai_vision_enabled; grupo 137, Prompt 07, más
     * la misión personalizacion-agente-whatsapp) se editan desde la pantalla de
     * Configuración del módulo WhatsApp, solo visible para el dueño. Ambas pantallas
     * pegan al mismo endpoint pero cada una manda solo sus propios campos: cada campo
     * se arma con `$request->has(...)` para no pisar con `null`/`false` lo que la otra
     * pantalla ya tiene guardado.
     */
    public function update_config(Request $request): JsonResponse
    {
        if (! $this->is_owner()) {
            return response()->json(['message' => 'Solo el dueño puede editar la configuración de WhatsApp.'], 403);
        }

        // `sometimes` en todas las reglas es la clave de que las dos pantallas (ABM →
        // Integraciones y el modal del módulo) puedan seguir mandando payloads parciales sin
        // pisarse: si el campo no vino, no se valida ni se toca.
        $request->validate([
            'kapso_api_key'            => 'sometimes|required|string|max:191',
            'phone_number_id'          => 'sometimes|required|string|max:191',
            'webhook_secret'           => 'sometimes|required|string|max:191',
            'is_active'                => 'sometimes|boolean',
            'agent_personality'        => 'sometimes|nullable|string|max:5000',
            'ai_enabled_default'       => 'sometimes|boolean',
            'auto_send_sale_pdf'       => 'sometimes|boolean',
            // Techos elegidos: 10 min para la espera de respuesta y 1 h para la de
            // confirmación. Más que eso y el problema real pasa a ser la ventana de 24 h de
            // Meta, no la espera.
            'ai_reply_delay_seconds'   => 'sometimes|integer|min:0|max:600',
            'ai_confirm_delay_seconds' => 'sometimes|integer|min:0|max:3600',
            // Si el agente mira las fotos que manda el cliente (misión whatsapp-sidebar-multimedia).
            'ai_vision_enabled'        => 'sometimes|boolean',
            // Habilidades del agente IA (rubro, vocabulario, qué preguntar), texto libre del
            // dueño (misión personalizacion-agente-whatsapp).
            'agent_skills'             => 'sometimes|nullable|string|max:5000',
        ]);

        // Se arranca vacío y se agrega cada campo solo si vino en el request.
        $data = [];

        if ($request->has('kapso_api_key')) {
            $data['kapso_api_key'] = $request->kapso_api_key;
        }
        if ($request->has('phone_number_id')) {
            $data['phone_number_id'] = $request->phone_number_id;
        }
        if ($request->has('webhook_secret')) {
            $data['webhook_secret'] = $request->webhook_secret;
        }
        if ($request->has('is_active')) {
            $data['is_active'] = (bool) $request->is_active;
        }

        // Personalidad del agente IA (texto libre definido por el dueño).
        if ($request->has('agent_personality')) {
            $data['agent_personality'] = $request->agent_personality;
        }

        // Si los chats nuevos nacen con la respuesta automática de IA prendida.
        if ($request->has('ai_enabled_default')) {
            $data['ai_enabled_default'] = (bool) $request->ai_enabled_default;
        }

        // Si se envía el comprobante PDF de la venta automáticamente por WhatsApp.
        if ($request->has('auto_send_sale_pdf')) {
            $data['auto_send_sale_pdf'] = (bool) $request->auto_send_sale_pdf;
        }

        // Segundos que espera el agente antes de responder (agrupa mensajes seguidos del cliente).
        if ($request->has('ai_reply_delay_seconds')) {
            $data['ai_reply_delay_seconds'] = (int) $request->ai_reply_delay_seconds;
        }

        // Segundos que la respuesta generada espera confirmación humana antes de auto-enviarse.
        if ($request->has('ai_confirm_delay_seconds')) {
            $data['ai_confirm_delay_seconds'] = (int) $request->ai_confirm_delay_seconds;
        }

        // Si el agente interpreta las fotos que manda el cliente (apagado de fábrica: cada foto
        // que analiza tiene un costo extra de tokens de visión).
        //
        // 🔴 VA EN SU PROPIO `if ($request->has(...))`, COMO TODOS LOS DE ARRIBA, Y NO ADENTRO
        // DE UN BLOQUE COMPARTIDO CON LOS OTROS TOGGLES DEL AGENTE. Las dos pantallas de
        // configuración (Conexión y Agente) pegan a este mismo endpoint y cada una manda solo
        // sus campos: agrupar dos campos en un `has()` hace que la pantalla que manda uno le
        // escriba `false` al otro. El switch quedaría apagándose solo al guardar cualquier otra
        // cosa, sin ningún error a la vista.
        if ($request->has('ai_vision_enabled')) {
            $data['ai_vision_enabled'] = (bool) $request->ai_vision_enabled;
        }

        // Habilidades del agente IA (texto libre definido por el dueño: rubro, vocabulario,
        // qué preguntar). Mismo motivo que arriba: va en su propio `if`, nunca agrupado.
        if ($request->has('agent_skills')) {
            $data['agent_skills'] = $request->agent_skills;
        }

        $user_id = $this->userId();
        $current_config = WhatsappBotConfig::where('user_id', $user_id)->first();

        // Guard de coherencia: el bot no se puede dejar activo sin las tres credenciales. Se
        // mira el valor FINAL de cada campo (el que vino en el request o, si no vino, el ya
        // persistido), porque cada pantalla manda solo sus campos.
        $will_be_active = array_key_exists('is_active', $data)
            ? (bool) $data['is_active']
            : (! is_null($current_config) ? (bool) $current_config->is_active : false);

        if ($will_be_active) {
            foreach (['phone_number_id', 'kapso_api_key', 'webhook_secret'] as $campo_tecnico) {
                $valor_final = array_key_exists($campo_tecnico, $data)
                    ? $data[$campo_tecnico]
                    : (! is_null($current_config) ? $current_config->{$campo_tecnico} : null);

                if (trim((string) $valor_final) === '') {
                    return response()->json([
                        'message' => 'No se puede activar el bot sin phone_number_id, kapso_api_key y webhook_secret.',
                    ], 422);
                }
            }
        }

        // Las tres columnas técnicas son NOT NULL sin default: si todavía no existe la fila y
        // el modal del agente guarda solo sus campos (personalidad, toggles, esperas), el
        // updateOrCreate revienta en MySQL estricto. Se rellenan con '' las que no vinieron
        // — solo en el alta, para no pisar nunca lo que ya está guardado.
        if (is_null($current_config)) {
            foreach (['kapso_api_key', 'phone_number_id', 'webhook_secret'] as $campo_tecnico) {
                if (! array_key_exists($campo_tecnico, $data)) {
                    $data[$campo_tecnico] = '';
                }
            }
        }

        $config = WhatsappBotConfig::updateOrCreate(
            ['user_id' => $user_id],
            $data
        );

        return response()->json(['model' => $config], 200);
    }

    /**
     * Inyecta un mensaje entrante como si lo hubiera mandado el cliente por WhatsApp (misión
     * whatsapp-agente). Sirve para probar el agente de punta a punta sin depender de que
     * alguien escriba de verdad a un número de Kapso.
     *
     * Recorre EXACTAMENTE el mismo camino interno que el webhook real —persistencia del chat y
     * del mensaje, ventana de 24 h, agrupación con su demora, generación de la respuesta,
     * estado de confirmación y registro de tokens—, pero con dos diferencias que no son
     * cosméticas y que son todo el punto de este endpoint:
     *
     *  1. El mensaje queda marcado con `is_simulated = 1`, y el chat con
     *     `last_inbound_simulated = 1`. `direction = 'in'` y `source = 'cliente'` siguen
     *     siendo idénticos a los de un mensaje real a propósito (el flujo tiene que ser el
     *     mismo), así que sin esa marca el registro comercial quedaba falseado: un empleado
     *     leía mañana un mensaje que el cliente nunca escribió y no tenía cómo distinguirlo.
     *  2. La respuesta que genera el agente NUNCA sale hacia Kapso: la frena
     *     `WhatsappBotSendService::chat_en_simulacion()` (leer el comentario de ese método
     *     antes de tocar nada). Se persiste igual y se ve en la conversación, así que Lucas
     *     lee lo que el agente contestó sin que se le haya mandado nada a nadie.
     *
     * 🔴 La config se resuelve SIEMPRE por el usuario autenticado, nunca con el atajo
     * mono-tenant del webhook (`where('is_active', true)->first()`). Eso es lo único que
     * impide que el dueño de una empresa inyecte mensajes en la cuenta de otra.
     *
     * La ruta lleva además un `throttle` propio y bajo (ver `routes/api.php`): cada llamada
     * gasta un embedding de OpenAI más una respuesta de Anthropic, y con el límite genérico
     * de 300/min eso eran 300 llamadas pagas por minuto sin techo.
     *
     * @param  Request  $request  Espera `phone` y `body`.
     * @return JsonResponse
     */
    public function simulate_inbound(Request $request): JsonResponse
    {
        if (! $this->is_owner()) {
            return response()->json(['message' => 'Solo el dueño puede simular mensajes entrantes.'], 403);
        }

        $request->validate([
            'phone' => 'required|string|max:40',
            'body'  => 'required|string|max:2000',
        ]);

        $user_id = (int) $this->userId();

        $config = WhatsappBotConfig::where('user_id', $user_id)->first();
        if (is_null($config)) {
            return response()->json(['message' => 'Todavía no hay una configuración de WhatsApp para esta empresa.'], 422);
        }

        $phone = trim((string) $request->phone);
        $body  = trim((string) $request->body);

        if ($body === '') {
            return response()->json(['message' => 'El mensaje no puede estar vacío.'], 422);
        }

        // Payload mínimo con la misma forma que el de Kapso, para que `store_inbound_message`
        // reciba lo mismo que recibiría en un mensaje real.
        $payload = [
            'message' => [
                'from' => $phone,
                'type' => 'text',
                'text' => ['body' => $body],
            ],
        ];

        Log::channel('daily')->info('WhatsappBotController: mensaje entrante SIMULADO.', [
            'user_id'      => $user_id,
            'auth_user_id' => $this->userId(false),
            'phone'        => $phone,
            'body'         => mb_substr($body, 0, 120),
        ]);

        // Mismo camino que el webhook real: persiste el chat y el mensaje 'in', abre la
        // ventana de 24 h (store_inbound_message setea last_inbound_at = now()) y dispara el
        // agente con su debounce. El `true` final marca el mensaje y el chat como simulados:
        // es lo que hace que esa ventana forzada no se pueda usar para enviar de verdad.
        $this->process_inbound($config, $phone, $body, $payload, true);

        $chat = WhatsappChat::where('user_id', $user_id)
            ->where('phone', WhatsappPhoneHelper::normalize($phone))
            ->first();

        return response()->json([
            'model' => is_null($chat) ? null : $this->fullModel('WhatsappChat', $chat->id),
        ], 201);
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
     * Extrae from, type, body y el descriptor del adjunto del payload de Kapso.
     *
     * Tipos soportados: `text`, `audio`/`ptt`/`voice` e `image` (D15). `document`, `video` y
     * `sticker` siguen devolviendo `null` a propósito: cada tipo nuevo necesita además una
     * forma de dibujarse en la burbuja de la conversación, y el alcance de la misión son
     * audios e imágenes. Queda anotado como limitación conocida.
     *
     * ⚠️ Este es UNO de los dos guards que descartaban las imágenes. El otro es el corte por
     * cuerpo vacío de `handle_message_received()`: agregar `'image'` acá sin tocar aquél deja
     * pasar la foto con epígrafe y sigue perdiendo la foto sin epígrafe, que es el caso normal.
     *
     * @return array{from: string, type: string, body: string|null, media: array|null}|null
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

        // Texto, nota de voz e imagen (D15).
        if (! in_array($raw_type, ['text', 'audio', 'ptt', 'voice', 'image'], true)) {
            return null;
        }

        // El descriptor del adjunto se resuelve ANTES del cuerpo porque el epígrafe de la
        // imagen sale de ahí: la clave del nodo donde vive el adjunto no siempre es el `type`
        // del mensaje (una nota de voz puede llegar como `type: ptt` con nodo `audio`), y esa
        // traducción vive en un solo lugar, adentro del servicio.
        $media = null;
        if ($raw_type !== 'text') {
            $media = (new WhatsappInboundMediaService())->extract_inbound_media($message, $raw_type);
        }

        $body = null;
        if ($raw_type === 'text') {
            $body = isset($message['text']['body']) ? trim((string) $message['text']['body']) : null;
        } elseif ($raw_type === 'image') {
            // Una imagen sin epígrafe NO tiene cuerpo, y antes eso alcanzaba para que el
            // mensaje se descartara entero. El literal la deja existir en la conversación y en
            // el historial que ve el agente ("llegó una foto"). La burbuja lo esconde cuando
            // hay miniatura, así que el operador no ve el relleno.
            $caption = (isset($media['caption'])) ? trim((string) $media['caption']) : '';
            $body = $caption !== '' ? $caption : '[Imagen recibida]';
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
            'from'  => (string) $from,
            'type'  => $raw_type,
            'body'  => $body,
            'media' => $media,
        ];
    }
}
