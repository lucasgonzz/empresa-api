<?php

namespace App\Http\Controllers\Helpers;

use App\Events\WhatsappChatUpdated;
use App\Models\Client;
use App\Models\WhatsappBotConfig;
use App\Models\WhatsappChat;
use App\Models\WhatsappChatMessage;
use App\Services\WhatsappAiAutoSendScheduler;
use Illuminate\Support\Facades\Log;

/**
 * Lógica de negocio del módulo de chats de WhatsApp con clientes (grupo 137, Prompt 02):
 * persistencia de mensajes entrantes/salientes del webhook, auto-vinculación de cliente
 * por teléfono, estados de entrega y broadcast en tiempo real. Los controllers
 * (`WhatsappBotController@receive` y `WhatsappChatController`) solo orquestan; toda la
 * lógica vive acá para no repetirla entre el webhook y los endpoints manuales.
 */
class WhatsappChatHelper
{
    /**
     * Orden de "avance" de los estados de entrega: un estado nunca retrocede
     * (ej: si ya está `leido`, un evento `delivered` tardío no lo vuelve a `entregado`).
     * `fallido` queda al mismo nivel que `enviado` para no pisar un `entregado`/`leido`
     * ya confirmado si llegara un `failed` fuera de orden.
     *
     * @var array<string, int>
     */
    private static $status_rank = [
        'pendiente' => 0,
        'enviado'   => 1,
        'fallido'   => 1,
        'entregado' => 2,
        'leido'     => 3,
    ];

    /**
     * Busca o crea el `WhatsappChat` del teléfono entrante, guarda el mensaje `in`,
     * actualiza los timestamps/contador de no leídos, auto-vincula el cliente (solo si
     * matchea EXACTAMENTE uno) y emite el broadcast del mensaje nuevo.
     *
     * @param  int  $user_id  Owner dueño del bot (config->user_id).
     * @param  string  $from  Teléfono del cliente tal como lo manda Kapso.
     * @param  string  $body  Texto (o transcripción de audio) del mensaje entrante.
     * @param  array  $payload  Payload completo del webhook, para extraer el nombre de contacto.
     * @param  WhatsappBotConfig  $config  Configuración activa del bot de ese owner.
     * @return WhatsappChat  El chat (nuevo o existente) ya persistido con el mensaje aplicado.
     */
    public static function store_inbound_message($user_id, $from, $body, array $payload, WhatsappBotConfig $config)
    {
        // Teléfono siempre normalizado (solo dígitos) al persistirlo, así el listado y el
        // matching de auto-vinculación son consistentes.
        $phone = WhatsappPhoneHelper::normalize($from);

        $chat = WhatsappChat::where('user_id', $user_id)
            ->where('phone', $phone)
            ->first();

        if (is_null($chat)) {
            $chat = new WhatsappChat();
            $chat->user_id = $user_id;
            $chat->phone = $phone;
            // Nace con la respuesta automática según lo que definió el dueño para chats nuevos.
            $chat->ai_enabled = (bool) $config->ai_enabled_default;
            $chat->unread_count = 0;
            // Auto-vinculación: solo si hay un único cliente del owner cuyo teléfono matchea;
            // con 0 o más de 1 coincidencia se deja sin vincular (el usuario lo hace a mano).
            $chat->client_id = self::find_unique_matching_client_id($user_id, $phone);
        }

        // Si Kapso mandó nombre de contacto y todavía no tenemos uno cargado, lo usamos.
        $contact_name = self::extract_contact_name($payload);
        if (! is_null($contact_name) && empty($chat->display_name)) {
            $chat->display_name = $contact_name;
        }

        $chat->last_message_at = now();
        // Único campo que define la ventana de 24 h de Meta (WhatsappChat::is_within_service_window()).
        $chat->last_inbound_at = now();
        $chat->unread_count = ((int) $chat->unread_count) + 1;
        $chat->save();

        $message = WhatsappChatMessage::create([
            'whatsapp_chat_id' => $chat->id,
            'direction' => 'in',
            'source' => 'cliente',
            'body' => $body,
        ]);

        self::broadcast_update($user_id, $chat, $message);

        return $chat;
    }

    /**
     * Guarda la respuesta del agente de IA como mensaje `out` YA ENVIADO y emite el
     * broadcast. `delivery_status` nace en `pendiente`: se actualiza más adelante con los
     * eventos `whatsapp.message.sent/delivered/read/failed` (ver `handle_delivery_status_event`).
     *
     * ⚠️ Desde la misión whatsapp-agente el flujo automático NO pasa más por acá: el agente
     * crea la respuesta con `store_pending_ai_message()` y recién cuando sale de verdad la
     * marca con `mark_ai_message_sent()`. El método se deja porque sigue siendo el camino
     * correcto para cualquier caller que envíe primero y persista después, y se le pasa
     * `ai_status = 'enviado'` para que la fila quede coherente con el eje nuevo.
     *
     * @param  WhatsappChat  $chat  Chat al que pertenece la respuesta.
     * @param  string  $body  Texto generado por la IA.
     * @param  string|null  $wa_message_id  Id que devolvió Kapso al enviar (puede no venir).
     * @return WhatsappChatMessage
     */
    public static function store_outbound_ai_message(WhatsappChat $chat, $body, $wa_message_id)
    {
        return self::store_outbound_message($chat, $body, $wa_message_id, 'ia', null, null, null, null, 'enviado');
    }

    /**
     * Guarda una respuesta del agente que TODAVÍA NO SE ENVIÓ, esperando la confirmación de
     * una persona (misión whatsapp-agente). Nace con `ai_status = 'a_confirmar'` y sin
     * `wa_message_id`, porque no pasó por Kapso: la manda después
     * `AutoSendWhatsappAiMessageJob` (si vence la espera) o el endpoint de confirmación.
     *
     * Sí mueve `last_message_at` y broadcastea a propósito: el chat tuvo actividad y el
     * operador necesita verlo flotar arriba de la lista para poder confirmarlo o descartarlo.
     *
     * @param  WhatsappChat  $chat  Chat al que pertenece la respuesta.
     * @param  string  $body  Texto generado por la IA.
     * @return WhatsappChatMessage
     */
    public static function store_pending_ai_message(WhatsappChat $chat, $body)
    {
        return self::store_outbound_message($chat, $body, null, 'ia', null, null, null, null, 'a_confirmar');
    }

    /**
     * Marca como enviada una respuesta del agente que estaba esperando confirmación: le
     * carga el `wa_message_id` que devolvió Kapso, le saca la fecha de auto-envío (ya no hay
     * contador que mostrar) y la pasa a `ai_status = 'enviado'`, que es el valor con el que
     * entra al historial que ve la IA.
     *
     * `delivery_status` NO se toca: sigue en `pendiente` hasta que llegue el evento de Meta.
     * Son dos ejes distintos ("¿lo aprobó un humano?" vs "¿lo entregó Meta?") y mezclarlos
     * rompería el rankeo anti-retroceso de `handle_delivery_status_event()`.
     *
     * @param  WhatsappChatMessage  $message  Mensaje con `ai_status = 'a_confirmar'`.
     * @param  string|null  $wa_message_id  Id que devolvió Kapso al enviar (puede no venir).
     * @return WhatsappChatMessage
     */
    public static function mark_ai_message_sent(WhatsappChatMessage $message, $wa_message_id)
    {
        $message->ai_status = 'enviado';
        $message->wa_message_id = $wa_message_id;
        $message->ai_auto_send_at = null;
        $message->save();

        $chat = WhatsappChat::find($message->whatsapp_chat_id);
        if (! is_null($chat)) {
            $chat->last_message_at = now();
            $chat->save();

            self::broadcast_update((int) $chat->user_id, $chat, $message);
        }

        return $message;
    }

    /**
     * Borra las respuestas del agente que están esperando confirmación en un chat, cancelando
     * antes su job de auto-envío. Se llama cuando el cliente vuelve a escribir.
     *
     * Se BORRAN, no se marcan (decisión D2 del plan), por dos motivos: el historial que ve la
     * IA leería un `direction = 'out'` que nunca se envió y creería que ya contestó; y el
     * operador vería en la conversación un mensaje que el cliente jamás recibió.
     *
     * @param  WhatsappChat  $chat
     * @return int  Cantidad de mensajes descartados.
     */
    public static function discard_pending_ai_messages(WhatsappChat $chat)
    {
        $pending_ids = WhatsappChatMessage::where('whatsapp_chat_id', $chat->id)
            ->where('ai_status', 'a_confirmar')
            ->pluck('id');

        if ($pending_ids->isEmpty()) {
            return 0;
        }

        Log::channel('daily')->info('WhatsappChatHelper: respuestas del agente descartadas (el cliente siguió escribiendo).', [
            'chat_id'     => $chat->id,
            'message_ids' => $pending_ids->all(),
        ]);

        $auto_send_scheduler = new WhatsappAiAutoSendScheduler();
        foreach ($pending_ids as $pending_id) {
            $auto_send_scheduler->cancel_for_message((int) $pending_id);
        }

        WhatsappChatMessage::whereIn('id', $pending_ids)->delete();

        // Sin mensaje: el front no puede "actualizar" una fila que ya no existe, tiene que
        // recargar los mensajes del chat.
        self::broadcast_update((int) $chat->user_id, $chat);

        return $pending_ids->count();
    }

    /**
     * Guarda un mensaje `out` enviado a mano por un empleado desde el módulo (endpoint
     * `POST whatsapp-chats/{id}/messages`) y emite el broadcast.
     *
     * @param  WhatsappChat  $chat  Chat al que pertenece el mensaje.
     * @param  string  $body  Texto escrito por el operador.
     * @param  string|null  $wa_message_id  Id que devolvió Kapso al enviar (puede no venir).
     * @param  int|null  $sent_by_user_id  Empleado autenticado que lo mandó.
     * @return WhatsappChatMessage
     */
    public static function store_outbound_manual_message(WhatsappChat $chat, $body, $wa_message_id, $sent_by_user_id)
    {
        return self::store_outbound_message($chat, $body, $wa_message_id, 'manual', $sent_by_user_id);
    }

    /**
     * Guarda un mensaje `out` enviado como plantilla de Meta (endpoint
     * `POST whatsapp-chats/{id}/send-template`, grupo 137 Prompt 04) y emite el
     * broadcast. Es el único camino para responder cuando la ventana de 24 h está
     * cerrada. `$body` ya debe venir con las variables reemplazadas
     * (`WhatsappTemplate::render_body()`), no con los placeholders `{{1}}`, `{{2}}`...
     *
     * @param  WhatsappChat  $chat  Chat al que pertenece el mensaje.
     * @param  string  $body  Preview de la plantilla con las variables ya reemplazadas.
     * @param  string|null  $wa_message_id  Id que devolvió Kapso al enviar (puede no venir).
     * @param  int|null  $sent_by_user_id  Empleado autenticado que lo mandó.
     * @param  string  $template_meta_name  Nombre técnico de la plantilla usada en Meta.
     * @return WhatsappChatMessage
     */
    public static function store_outbound_template_message(WhatsappChat $chat, $body, $wa_message_id, $sent_by_user_id, $template_meta_name)
    {
        return self::store_outbound_message($chat, $body, $wa_message_id, 'plantilla', $sent_by_user_id, $template_meta_name);
    }

    /**
     * Guarda un mensaje `out` con un documento adjunto (grupo 137, Prompt 05: comprobante
     * de venta enviado por el agente, manual o automático) y emite el broadcast. `$source`
     * distingue el origen: 'sistema' (job automático), 'manual' (botón del modal de Ventas)
     * o 'plantilla' (ventana cerrada, se mandó como header DOCUMENT de `cc_cli_comprobante`).
     *
     * @param  WhatsappChat  $chat  Chat al que pertenece el mensaje.
     * @param  string  $body  Caption o preview de la plantilla ya con las variables reemplazadas.
     * @param  string|null  $wa_message_id  Id que devolvió Kapso al enviar (puede no venir).
     * @param  int|null  $sent_by_user_id  Empleado autenticado que lo mandó (null si fue automático).
     * @param  string  $source  'sistema' | 'manual' | 'plantilla'.
     * @param  string  $media_url  URL pública del documento enviado.
     * @param  string|null  $template_meta_name  Solo aplica cuando `$source` = 'plantilla'.
     * @return WhatsappChatMessage
     */
    public static function store_outbound_document_message(WhatsappChat $chat, $body, $wa_message_id, $sent_by_user_id, $source, $media_url, $template_meta_name = null)
    {
        return self::store_outbound_message($chat, $body, $wa_message_id, $source, $sent_by_user_id, $template_meta_name, 'document', $media_url);
    }

    /**
     * Lógica común de persistencia de un mensaje saliente (compartida entre la respuesta
     * de IA, el envío manual, el envío de plantilla y el envío de documentos desde el módulo).
     *
     * @param  WhatsappChat  $chat
     * @param  string  $body
     * @param  string|null  $wa_message_id
     * @param  string  $source  'ia' | 'manual' | 'plantilla' | 'sistema'.
     * @param  int|null  $sent_by_user_id
     * @param  string|null  $template_meta_name  Solo aplica a source 'plantilla'.
     * @param  string|null  $media_type  'document' si el mensaje trae un adjunto (Prompt 05), null si es texto plano.
     * @param  string|null  $media_url  URL del adjunto, solo si `$media_type` no es null.
     * @param  string|null  $ai_status  Eje de confirmación humana del agente (misión whatsapp-agente):
     *                                  null = no aplica (todo lo que no salga del agente),
     *                                  'a_confirmar' = generado y esperando que una persona lo apruebe,
     *                                  'enviado' = ya salió. Es independiente de `delivery_status`.
     * @return WhatsappChatMessage
     */
    private static function store_outbound_message(WhatsappChat $chat, $body, $wa_message_id, $source, $sent_by_user_id, $template_meta_name = null, $media_type = null, $media_url = null, $ai_status = null)
    {
        $message = WhatsappChatMessage::create([
            'whatsapp_chat_id' => $chat->id,
            'direction' => 'out',
            'source' => $source,
            'sent_by_user_id' => $sent_by_user_id,
            'body' => $body,
            'wa_message_id' => $wa_message_id,
            'delivery_status' => 'pendiente',
            'template_meta_name' => $template_meta_name,
            'media_type' => $media_type,
            'media_url' => $media_url,
            'ai_status' => $ai_status,
        ]);

        $chat->last_message_at = now();
        $chat->save();

        self::broadcast_update($chat->user_id, $chat, $message);

        return $message;
    }

    /**
     * Procesa un evento de estado de entrega de Kapso/Meta (`whatsapp.message.sent`,
     * `.delivered`, `.read` o `.failed`): busca el mensaje por `wa_message_id` y avanza
     * `delivery_status` sin retroceder. En `failed` guarda el motivo de forma defensiva
     * (el payload de error puede traer cualquier subconjunto de code/title/message).
     *
     * @param  string  $event_type  Uno de los cuatro eventos de estado soportados.
     * @param  array  $payload  Payload completo del webhook.
     * @return void
     */
    public static function handle_delivery_status_event($event_type, array $payload)
    {
        $new_status = self::map_event_to_status($event_type);
        if (is_null($new_status)) {
            return;
        }

        $wa_message_id = self::extract_status_message_id($payload);
        if (empty($wa_message_id)) {
            Log::channel('daily')->warning('WhatsappChatHelper: evento de estado sin wa_message_id.', [
                'event' => $event_type,
            ]);
            return;
        }

        $message = WhatsappChatMessage::where('wa_message_id', $wa_message_id)->first();
        if (is_null($message)) {
            // Puede pasar si el mensaje se mandó desde otro canal, o si el estado llega
            // antes de que termine de persistirse la respuesta de send_text (orden de red).
            Log::channel('daily')->warning('WhatsappChatHelper: no se encontró mensaje para el estado recibido.', [
                'event' => $event_type,
                'wa_message_id' => $wa_message_id,
            ]);
            return;
        }

        $current_rank = self::$status_rank[$message->delivery_status] ?? 0;
        $new_rank = self::$status_rank[$new_status] ?? 0;
        if ($new_rank <= $current_rank) {
            // Nunca retroceder (ej: leido no vuelve a entregado; un failed tardío no pisa un entregado/leido ya confirmado).
            return;
        }

        $message->delivery_status = $new_status;
        if ($new_status === 'fallido') {
            $message->send_error = self::extract_failure_reason($payload);
        }
        $message->save();

        $chat = WhatsappChat::find($message->whatsapp_chat_id);
        if (! is_null($chat)) {
            self::broadcast_update((int) $chat->user_id, $chat, $message);
        }
    }

    /**
     * Emite el broadcast `WhatsappChatUpdated` en el canal `whatsapp.{owner_id}`.
     * Nunca deja que un fallo del broadcast rompa el flujo (persistencia ya ocurrió).
     *
     * @param  int  $owner_id
     * @param  WhatsappChat  $chat
     * @param  WhatsappChatMessage|null  $message
     * @return void
     */
    public static function broadcast_update($owner_id, WhatsappChat $chat, $message = null)
    {
        try {
            event(new WhatsappChatUpdated((int) $owner_id, $chat, $message));
        } catch (\Throwable $e) {
            Log::channel('daily')->warning('WhatsappChatHelper: broadcast falló.', [
                'owner_id' => $owner_id,
                'chat_id' => $chat->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Busca, entre los clientes del owner con teléfono cargado, cuántos matchean el
     * teléfono entrante (últimos 10 dígitos). Devuelve el id solo si hay exactamente uno.
     *
     * @param  int  $user_id
     * @param  string  $phone  Ya normalizado (solo dígitos).
     * @return int|null
     */
    private static function find_unique_matching_client_id($user_id, $phone)
    {
        if ($phone === '') {
            return null;
        }

        $clients = Client::where('user_id', $user_id)
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->get(['id', 'phone']);

        $matching_ids = [];
        foreach ($clients as $client) {
            if (WhatsappPhoneHelper::matches($client->phone, $phone)) {
                $matching_ids[] = $client->id;
            }
        }

        return count($matching_ids) === 1 ? $matching_ids[0] : null;
    }

    /**
     * Extrae el nombre de contacto del payload de Kapso, si viene. Se revisan varias
     * rutas posibles porque no hay documentación cerrada del formato exacto (defensivo).
     *
     * @param  array  $payload
     * @return string|null
     */
    private static function extract_contact_name(array $payload)
    {
        $candidates = [
            isset($payload['contact']['name']) ? $payload['contact']['name'] : null,
            isset($payload['message']['contact']['name']) ? $payload['message']['contact']['name'] : null,
            isset($payload['conversation']['contact_name']) ? $payload['conversation']['contact_name'] : null,
            isset($payload['conversation']['contact']['name']) ? $payload['conversation']['contact']['name'] : null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }

    /**
     * Mapea el nombre del evento de webhook al valor de `delivery_status` de la tabla.
     *
     * @param  string  $event_type
     * @return string|null
     */
    private static function map_event_to_status($event_type)
    {
        switch ($event_type) {
            case 'whatsapp.message.sent':
                return 'enviado';
            case 'whatsapp.message.delivered':
                return 'entregado';
            case 'whatsapp.message.read':
                return 'leido';
            case 'whatsapp.message.failed':
                return 'fallido';
            default:
                return null;
        }
    }

    /**
     * Extrae el `wa_message_id` de un evento de estado. Se revisan varias rutas posibles
     * del payload (defensivo, formato no documentado de forma cerrada).
     *
     * @param  array  $payload
     * @return string|null
     */
    private static function extract_status_message_id(array $payload)
    {
        $candidates = [
            isset($payload['message']['id']) ? $payload['message']['id'] : null,
            isset($payload['message']['wa_message_id']) ? $payload['message']['wa_message_id'] : null,
            isset($payload['status']['id']) ? $payload['status']['id'] : null,
            isset($payload['status']['message_id']) ? $payload['status']['message_id'] : null,
            isset($payload['id']) ? $payload['id'] : null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
            if (is_int($candidate)) {
                return (string) $candidate;
            }
        }

        return null;
    }

    /**
     * Arma el texto de `send_error` a partir del objeto de error del payload de `failed`,
     * uniendo código + título + detalle (cualquier subconjunto presente; nunca rompe si
     * falta alguno).
     *
     * @param  array  $payload
     * @return string|null
     */
    private static function extract_failure_reason(array $payload)
    {
        $error = null;
        if (isset($payload['message']['errors'][0]) && is_array($payload['message']['errors'][0])) {
            $error = $payload['message']['errors'][0];
        } elseif (isset($payload['errors'][0]) && is_array($payload['errors'][0])) {
            $error = $payload['errors'][0];
        } elseif (isset($payload['error']) && is_array($payload['error'])) {
            $error = $payload['error'];
        }

        if (is_null($error)) {
            return null;
        }

        $parts = [];
        if (! empty($error['code'])) {
            $parts[] = 'Código: '.$error['code'];
        }
        if (! empty($error['title'])) {
            $parts[] = $error['title'];
        }
        if (! empty($error['message'])) {
            $parts[] = $error['message'];
        } elseif (! empty($error['detail'])) {
            $parts[] = $error['detail'];
        }

        return $parts === [] ? null : implode(' — ', $parts);
    }
}
