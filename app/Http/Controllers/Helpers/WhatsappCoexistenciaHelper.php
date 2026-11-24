<?php

namespace App\Http\Controllers\Helpers;

use App\Models\WhatsappBotConfig;
use App\Models\WhatsappChat;
use App\Models\WhatsappChatMessage;
use App\Services\WhatsappAgentScheduler;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Coexistencia de WhatsApp Business App (misión whatsapp-coexistencia-ecos).
 *
 * Con un número en coexistencia, Kapso avisa al webhook de lo que pasa en el celular del dueño:
 *  - `message.kapso.origin = business_app`: eco de un mensaje que el dueño escribió desde la app.
 *  - `message.kapso.origin = history_sync`: mensajes viejos importados al conectar el número.
 *
 * Reglas (las dos entradas comparten guardas, idempotencia y manejo de la carrera de creación):
 *  - El eco queda en la conversación como `out/celular` y aplica la misma intervención humana que el envío
 *    manual desde el módulo (descarta borradores, frena al agente y apaga la señal de pedido/consulta).
 *  - El historial queda como `historial` con su fecha original y NUNCA agenda al agente, abre la ventana de
 *    24 h, suma no leídos, avisa ni valora respuestas del agente.
 *  - Sólo se procesa si el `phone_number_id` del payload coincide con el de la configuración (aislamiento).
 *  - Idempotente por wamid dentro del chat: un reintento o un duplicado no produce ningún efecto.
 *
 * Todo entra por `WhatsappBotController::receive()`, dentro de su `try`: este helper nunca debe romper el 200.
 */
class WhatsappCoexistenciaHelper
{
    const SOURCE_ECO = 'celular';

    const SOURCE_HISTORIAL = 'historial';

    /**
     * Registra el eco de un mensaje escrito desde la app Business del celular.
     *
     * @param  WhatsappBotConfig  $config
     * @param  array  $payload  Payload completo del webhook.
     * @return void
     */
    public static function registrar_eco(WhatsappBotConfig $config, array $payload)
    {
        self::registrar($config, $payload, 'eco', 'out');
    }

    /**
     * Registra un mensaje del historial importado (entrante o saliente) sin ningún efecto sobre el agente.
     *
     * @param  WhatsappBotConfig  $config
     * @param  array  $payload  Payload completo del webhook.
     * @param  string  $event_type  `whatsapp.message.received` o `whatsapp.message.sent` (fallback de dirección).
     * @return void
     */
    public static function registrar_historial(WhatsappBotConfig $config, array $payload, $event_type)
    {
        $kapso_direction = isset($payload['message']['kapso']['direction'])
            ? strtolower(trim((string) $payload['message']['kapso']['direction']))
            : '';

        if ($kapso_direction === 'inbound') {
            $direction = 'in';
        } elseif ($kapso_direction === 'outbound') {
            $direction = 'out';
        } else {
            $direction = $event_type === 'whatsapp.message.received' ? 'in' : 'out';
        }

        self::registrar($config, $payload, 'historial', $direction);
    }

    /**
     * Camino común: guardas, tipo, teléfono y transacción con un único reintento ante la carrera de creación.
     *
     * @param  WhatsappBotConfig  $config
     * @param  array  $payload
     * @param  string  $modo  `eco` | `historial`
     * @param  string  $direction  `in` | `out`
     * @return void
     */
    private static function registrar(WhatsappBotConfig $config, array $payload, $modo, $direction)
    {
        $message = isset($payload['message']) && is_array($payload['message']) ? $payload['message'] : [];

        $wamid = isset($message['id']) ? trim((string) $message['id']) : '';
        if ($wamid === '') {
            Log::channel('daily')->warning('WhatsappCoexistenciaHelper: mensaje sin id, se ignora.', ['modo' => $modo]);

            return;
        }

        // Aislamiento estricto: ambos phone_number_id no vacíos e iguales. Config vacía, payload sin el campo o
        // distinto → no se crea nada (un número de otro comercio, o una conexión a medio configurar, no escribe acá).
        $pnid_config = trim((string) $config->phone_number_id);
        $pnid_payload = '';
        if (isset($payload['phone_number_id']) && trim((string) $payload['phone_number_id']) !== '') {
            $pnid_payload = trim((string) $payload['phone_number_id']);
        } elseif (isset($payload['conversation']['phone_number_id'])) {
            $pnid_payload = trim((string) $payload['conversation']['phone_number_id']);
        }
        if ($pnid_config === '' || $pnid_payload === '' || $pnid_config !== $pnid_payload) {
            Log::channel('daily')->warning('WhatsappCoexistenciaHelper: phone_number_id ausente o distinto del configurado, se ignora.', [
                'modo' => $modo,
                'wamid' => $wamid,
            ]);

            return;
        }

        // Reacciones (nativas o en el formato legado en texto), sistema y cualquier tipo fuera de la lista:
        // sin fila y sin efectos. Se mira antes de abrir la transacción.
        $contenido = null;
        if (is_null(WhatsappReactionHelper::extract_reaction($payload))) {
            $contenido = self::armar_contenido($message, $modo);
        }
        if (is_null($contenido)) {
            Log::channel('daily')->info('WhatsappCoexistenciaHelper: tipo sin registro, se ignora.', [
                'modo' => $modo,
                'wamid' => $wamid,
                'type' => isset($message['type']) ? (string) $message['type'] : null,
            ]);

            return;
        }

        $phone = self::resolver_telefono($payload, $message, $direction);
        if ($phone === '') {
            Log::channel('daily')->warning('WhatsappCoexistenciaHelper: mensaje sin teléfono, se ignora.', [
                'modo' => $modo,
                'wamid' => $wamid,
            ]);

            return;
        }

        $resultado = null;
        for ($intento = 1; $intento <= 2; $intento++) {
            try {
                $resultado = DB::transaction(function () use ($config, $payload, $modo, $direction, $wamid, $phone, $contenido) {
                    return self::guardar($config, $payload, $modo, $direction, $wamid, $phone, $contenido);
                });
                break;
            } catch (QueryException $e) {
                if (! self::es_carrera($e)) {
                    throw $e;
                }
                if ($intento === 2) {
                    // Se rinde sin relanzar: este log es el único `error` del evento (el `catch` del controlador
                    // no lo duplica). El 200 hacia Kapso se mantiene.
                    Log::channel('daily')->error('WhatsappCoexistenciaHelper: la carrera de creación del chat persistió tras el reintento.', [
                        'modo' => $modo,
                        'wamid' => $wamid,
                        'error' => $e->getMessage(),
                    ]);

                    return;
                }
            }
        }

        // El broadcast va fuera de la transacción (ya confirmada). El historial no emite por mensaje: una
        // importación de meses serían miles de eventos Pusher; la bandeja lo ve al recargar.
        if ($modo === 'eco' && is_array($resultado)) {
            WhatsappChatHelper::broadcast_update($config->user_id, $resultado['chat'], $resultado['message']);
        }
    }

    /**
     * Escritura dentro de la transacción: chat bloqueado, chequeo de wamid, fila y efectos del modo.
     *
     * @return array|null  `['chat' => WhatsappChat, 'message' => WhatsappChatMessage]` o null si no hubo nada que hacer.
     */
    private static function guardar(WhatsappBotConfig $config, array $payload, $modo, $direction, $wamid, $phone, array $contenido)
    {
        $user_id = $config->user_id;

        $chat = WhatsappChat::where('user_id', $user_id)->where('phone', $phone)->lockForUpdate()->first();

        $momento = $modo === 'historial' ? self::momento_original($payload) : now();

        if (is_null($chat)) {
            $chat = new WhatsappChat();
            $chat->user_id = $user_id;
            $chat->phone = $phone;
            $chat->ai_enabled = (bool) $config->ai_enabled_default;
            $chat->unread_count = 0;
            $chat->client_id = WhatsappChatHelper::find_unique_matching_client_id($user_id, $phone);
            $chat->last_message_at = $momento;
            $contact_name = WhatsappChatHelper::extract_contact_name($payload);
            if (! is_null($contact_name)) {
                $chat->display_name = $contact_name;
            }
            // Si otra entrega creó el chat en el medio, esta INSERT falla con 1062 y `registrar()` reintenta.
            $chat->save();
        } elseif (WhatsappChatMessage::where('whatsapp_chat_id', $chat->id)->where('wa_message_id', $wamid)->exists()) {
            // Idempotencia: ese wamid ya está (reintento, duplicado, o el mismo mensaje entró vivo, por API o por
            // historial). Sin fila y sin efectos.
            return null;
        }

        $columnas = [
            'whatsapp_chat_id' => $chat->id,
            'direction' => $direction,
            'source' => $modo === 'eco' ? self::SOURCE_ECO : self::SOURCE_HISTORIAL,
            'sent_by_user_id' => null,
            'body' => $contenido['body'],
            'media_type' => $contenido['media_type'],
            'wa_message_id' => $wamid,
            'delivery_status' => $direction === 'out' ? 'enviado' : 'pendiente',
            'is_simulated' => 0,
        ];
        if ($modo === 'historial') {
            $columnas['created_at'] = $momento;
            $columnas['updated_at'] = $momento;
        }
        $message = WhatsappChatMessage::create($columnas);

        if ($modo === 'historial') {
            // Nunca retrocede ni salta a "ahora"; jamás toca last_inbound_at, unread_count, agenda ni follow-up.
            if (is_null($chat->last_message_at) || $momento->gt($chat->last_message_at)) {
                $chat->last_message_at = $momento;
                $chat->save();
            }

            return ['chat' => $chat, 'message' => $message];
        }

        // Eco: intervención humana, coherente con `WhatsappChatController::send_message`.
        WhatsappChatHelper::discard_pending_ai_messages($chat);
        // Además bumpea el token: frena un job de generación ya encolado o en curso (lo que el envío manual no hace).
        (new WhatsappAgentScheduler())->cancel((int) $chat->id);
        $chat->clear_follow_up();
        $chat->last_message_at = now();
        $chat->save();

        return ['chat' => $chat, 'message' => $message];
    }

    /**
     * Cuerpo y tipo de adjunto del mensaje según la lista cerrada de tipos que se registran. Devuelve null para
     * cualquier otro (reaction, system, unsupported, sin tipo…) y para un texto vacío.
     *
     * @param  array  $message
     * @param  string  $modo  `eco` | `historial` (cambia el literal de los placeholders).
     * @return array|null  `['body' => string, 'media_type' => string|null]`
     */
    private static function armar_contenido(array $message, $modo)
    {
        $type = isset($message['type']) ? strtolower(trim((string) $message['type'])) : '';
        $origen = $modo === 'historial' ? 'del historial' : 'desde el celular';

        if ($type === 'text') {
            $body = isset($message['text']['body']) ? trim((string) $message['text']['body']) : '';

            return $body === '' ? null : ['body' => $body, 'media_type' => null];
        }

        if (in_array($type, ['image', 'video', 'document'], true)) {
            $caption = isset($message[$type]['caption']) ? trim((string) $message[$type]['caption']) : '';
            $nombres = ['image' => 'Imagen', 'video' => 'Video', 'document' => 'Documento'];

            return [
                'body' => $caption !== '' ? $caption : '[' . $nombres[$type] . ' enviado ' . $origen . ']',
                'media_type' => $type,
            ];
        }

        if (in_array($type, ['audio', 'ptt', 'voice'], true)) {
            return ['body' => '[Audio enviado ' . $origen . ']', 'media_type' => 'audio'];
        }

        if (in_array($type, ['sticker', 'location', 'contacts', 'interactive', 'button'], true)) {
            return ['body' => '[Mensaje enviado ' . $origen . ']', 'media_type' => null];
        }

        return null;
    }

    /**
     * Teléfono normalizado del contacto: `to` en salientes, `from` en entrantes, con `conversation.phone_number`
     * de respaldo. '' si no hay (sólo BSUID).
     *
     * @return string
     */
    private static function resolver_telefono(array $payload, array $message, $direction)
    {
        $clave = $direction === 'out' ? 'to' : 'from';
        $crudo = isset($message[$clave]) ? trim((string) $message[$clave]) : '';
        if ($crudo === '' && isset($payload['conversation']['phone_number'])) {
            $crudo = trim((string) $payload['conversation']['phone_number']);
        }

        return $crudo === '' ? '' : (string) WhatsappPhoneHelper::normalize($crudo);
    }

    /**
     * Fecha original del mensaje de historial (`message.timestamp`, epoch) en la zona de la app; ahora si falta o
     * es inválida; nunca en el futuro.
     *
     * @return Carbon
     */
    private static function momento_original(array $payload)
    {
        $ts = isset($payload['message']['timestamp']) ? trim((string) $payload['message']['timestamp']) : '';
        if ($ts === '' || ! ctype_digit($ts) || (int) $ts <= 0) {
            return now();
        }

        $momento = Carbon::createFromTimestamp((int) $ts)->setTimezone(config('app.timezone'));

        return $momento->gt(now()) ? now() : $momento;
    }

    /**
     * ¿La excepción es la carrera de creación del chat (duplicado 1062) o un deadlock/serialización
     * (1213 / SQLSTATE 40001)? Sólo esas se reintentan.
     *
     * @return bool
     */
    private static function es_carrera(QueryException $e)
    {
        $info = is_array($e->errorInfo) ? $e->errorInfo : [];
        $codigo = isset($info[1]) ? (int) $info[1] : 0;
        $sqlstate = isset($info[0]) ? (string) $info[0] : '';

        return in_array($codigo, [1062, 1213], true) || $sqlstate === '40001';
    }
}
