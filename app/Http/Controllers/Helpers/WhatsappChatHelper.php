<?php

namespace App\Http\Controllers\Helpers;

use App\Events\WhatsappChatUpdated;
use App\Models\Client;
use App\Models\WhatsappBotConfig;
use App\Models\WhatsappChat;
use App\Models\WhatsappChatMessage;
use App\Services\WhatsappAiAutoSendScheduler;
use App\Services\WhatsappBotSendService;
use App\Services\WhatsappInboundMediaService;
use Illuminate\Support\Facades\Log;

/**
 * Lógica de negocio del módulo de chats de WhatsApp con clientes (grupo 137, Prompt 02):
 * persistencia de mensajes entrantes/salientes del webhook, auto-vinculación de cliente
 * por teléfono, estados de entrega y broadcast en tiempo real. Los controllers
 * (`WhatsappBotController@receive` y `WhatsappChatController`) solo orquestan; toda la
 * lógica vive acá para no repetirla entre el webhook y los endpoints manuales.
 *
 * 🔴 MÁQUINA DE ESTADOS DE `ai_status` (eje de confirmación humana del agente). Es un ciclo
 * de vida, no una etiqueta suelta, y hay exactamente un lugar donde se pasa de cada estado
 * al siguiente:
 *
 *   null          → el mensaje no salió del agente (entrantes, manuales, plantillas). No aplica.
 *   'a_confirmar' → generado, TODAVÍA NO SE ENVIÓ. Se puede descartar y no entra al historial
 *                   que ve la IA (`WhatsappBotAiService::fetch_recent_messages()`).
 *   'enviando'    → alguien (el operador desde el endpoint de confirmación, o el job de
 *                   auto-envío) ganó el derecho a mandarlo y el POST a Kapso está en vuelo.
 *                   Ya NO se puede descartar ni volver a tomar.
 *   'enviado'     → Kapso lo aceptó y devolvió `wa_message_id`. Recién acá cuenta como dicho.
 *
 * Y desde 'enviando' se vuelve a 'a_confirmar' si el envío falla, con
 * `delivery_status = 'fallido'` + `send_error`, para que el operador lo pueda reintentar y
 * para que la IA no crea que contestó algo que el cliente nunca recibió.
 */
class WhatsappChatHelper
{
    /** Resultado de `send_pending_ai_message()`: salió por WhatsApp y quedó marcado 'enviado'. */
    const ENVIO_HECHO = 'enviado';

    /**
     * Resultado de `send_pending_ai_message()`: la otra punta (el operador o el job de
     * auto-envío) ganó la transición de estado y lo está mandando ella. El que recibe esto
     * NO tiene que mandar nada: se retira.
     */
    const ENVIO_YA_TOMADO = 'ya_tomado';

    /**
     * Resultado de `send_pending_ai_message()`: se intentó y WhatsApp no lo aceptó. La fila
     * quedó en 'a_confirmar' con `delivery_status = 'fallido'` y el motivo en `send_error`.
     */
    const ENVIO_FALLIDO = 'fallido';

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
     * Minutos después de los cuales un mensaje trabado en `ai_status = 'enviando'` se
     * considera huérfano y se puede volver a tomar (ver `claim_ai_message_for_send()`).
     *
     * El número sale del peor caso real, no de la intuición: el cliente HTTP hacia Kapso
     * corta a los 15 segundos, así que a los 5 minutos no queda ningún proceso legítimo
     * que pueda estar enviando ese mensaje. Es holgado a propósito — bajarlo acerca el
     * riesgo de que dos puntas manden el mismo mensaje, que es peor que esperar un rato.
     */
    private const STALE_SENDING_MINUTES = 5;

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
     * @param  bool  $is_simulated  True solo cuando el mensaje lo inyectó el endpoint de
     *                              simulación (`WhatsappBotController@simulate_inbound`) y no
     *                              lo escribió nadie. Queda grabado en la fila del mensaje y,
     *                              denormalizado, en el chat: es lo que impide que la respuesta
     *                              del agente salga por Kapso hacia un número que nunca escribió.
     * @param  array|null  $media  Descriptor del adjunto (`media_type`, `media_url`, `media_id`,
     *                             `mime`, `caption`) que armó `parse_inbound_message()`, o null
     *                             si el mensaje es de texto. Va último y con default porque los
     *                             callers existentes pasan seis argumentos posicionales y PHP 7.4
     *                             no tiene argumentos nombrados.
     * @return WhatsappChat  El chat (nuevo o existente) ya persistido con el mensaje aplicado.
     */
    public static function store_inbound_message($user_id, $from, $body, array $payload, WhatsappBotConfig $config, $is_simulated = false, $media = null)
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
        // Denormalización del último entrante: mientras esto valga 1, la ventana de 24 h que
        // muestra el chat está FORZADA por una simulación y no se puede confiar en ella para
        // enviar (lo frena `WhatsappBotSendService`). Se escribe siempre, en los dos caminos,
        // así que un mensaje real posterior la apaga sola y el chat vuelve a operar normal.
        $chat->last_inbound_simulated = $is_simulated ? 1 : 0;
        $chat->unread_count = ((int) $chat->unread_count) + 1;
        $chat->save();

        // 🔴 EL ADJUNTO SE BAJA DESPUÉS DEL `save()` DEL CHAT, Y EL ORDEN ES TODO EL PUNTO DE
        // LA UNIDAD. Arriba ya quedó escrito `last_inbound_at`, que es el único campo que abre
        // la ventana de 24 h de Meta. Recién ahí se sale a la red a buscar el archivo, que es
        // lo que puede tardar o fallar: si el CDN de Kapso se cae, la ventana ya está abierta y
        // el operador le puede contestar al cliente igual. Bajar primero y guardar después
        // ataría la ventana —lo importante— al éxito de una descarga de un tercero, que es
        // exactamente el bug que esta misión viene a arreglar. No dar vuelta estas dos cosas.
        $media_columns = [];
        if (is_array($media)) {
            // `media_type` va SIEMPRE, con archivo o sin él: es lo que le dice a la
            // conversación "acá llegó una foto" aunque no se haya podido bajar (D14).
            $media_columns['media_type'] = isset($media['media_type']) ? $media['media_type'] : null;

            // El id del mensaje en WhatsApp se lee acá y se le pasa al servicio SOLO para armar
            // el nombre determinista del archivo. 🔴 NO SE PERSISTE (D17):
            // `handle_delivery_status_event()` busca por la columna `wa_message_id` sin filtrar
            // `direction`, así que sembrarla con ids de mensajes entrantes le abriría una
            // colisión — un evento de entrega podría caer sobre la fila equivocada.
            $wa_message_id = isset($payload['message']['id']) ? (string) $payload['message']['id'] : null;

            $stored = (new WhatsappInboundMediaService())->store_inbound_media(
                $media,
                (int) $chat->id,
                $wa_message_id,
                (string) $body,
                $config
            );

            // `null` significa "no hay archivo", no "no hay mensaje". El servicio nunca lanza.
            if (is_array($stored)) {
                $media_columns['media_path'] = $stored['media_path'];
                $media_columns['media_mime'] = $stored['media_mime'];
                $media_columns['media_size'] = $stored['media_size'];
            }
        }

        $message = WhatsappChatMessage::create(array_merge([
            'whatsapp_chat_id' => $chat->id,
            'direction' => 'in',
            'source' => 'cliente',
            'body' => $body,
            // Lo único que distingue en la base este mensaje de uno que el cliente escribió
            // de verdad: `direction` y `source` son idénticos a propósito (la simulación tiene
            // que recorrer el mismo camino), así que sin esta columna el registro comercial
            // quedaba falseado y la única traza era una línea de log.
            'is_simulated' => $is_simulated ? 1 : 0,
        ], $media_columns));

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
     * ÚNICO camino para mandarle al cliente una respuesta del agente que estaba esperando
     * confirmación. Lo usan las dos puntas que pueden querer mandarla: el endpoint de
     * confirmación (`WhatsappChatController::confirm_ai_message()`, el "mandalo ahora" del
     * operador) y `AutoSendWhatsappAiMessageJob` (venció el plazo y sale sola).
     *
     * 🔴 ESTÁ CENTRALIZADO ACÁ A PROPÓSITO, Y ES EL PUNTO DELICADO DE TODO EL FLUJO. Las dos
     * puntas corren en procesos distintos y pueden pisarse: el job se despierta, hace su POST
     * a Kapso —que tarda SEGUNDOS— y mientras tanto el operador aprieta "confirmar". Cancelar
     * el token del auto-envío no alcanza para evitarlo: el job ya lo leyó al arrancar, mucho
     * antes de llegar al POST. Si esto viviera duplicado en el controller y en el job, el
     * cliente recibiría el mismo mensaje dos veces.
     *
     * Lo que serializa las dos puntas es la transición condicional de `claim_ai_message_for_send()`:
     * el que la gana manda, el que la pierde se retira sin tocar nada.
     *
     * @param  WhatsappChatMessage  $message  Mensaje con `ai_status = 'a_confirmar'`.
     * @param  WhatsappChat  $chat  Chat al que pertenece (ya validado por el caller).
     * @param  WhatsappBotConfig  $config  Configuración del bot del dueño del chat.
     * @return string  Una de las constantes ENVIO_HECHO | ENVIO_YA_TOMADO | ENVIO_FALLIDO.
     */
    public static function send_pending_ai_message(WhatsappChatMessage $message, WhatsappChat $chat, WhatsappBotConfig $config)
    {
        if (! self::claim_ai_message_for_send($message)) {
            Log::channel('daily')->info('WhatsappChatHelper: envío omitido, la respuesta del agente ya la estaba mandando otro.', [
                'message_id' => $message->id,
                'chat_id'    => $chat->id,
            ]);

            return self::ENVIO_YA_TOMADO;
        }

        // Chat en simulación: el último entrante lo inyectó `simulate-inbound` y no lo escribió
        // nadie, así que esta respuesta NO puede salir a WhatsApp. El freno duro está igual en
        // `WhatsappBotSendService::chat_en_simulacion()` (y ahí está escrito el porqué
        // completo); el corte acá es para que la fila quede bien: si dejáramos que llegue al
        // `send_text()` de abajo, el null volvería por la rama de fallo y el operador vería un
        // "WhatsApp rechazó el envío, revisá la clave de Kapso" que es mentira — no falló nada.
        //
        // Se marca 'enviado' sin `wa_message_id` a propósito: la respuesta queda visible en la
        // conversación, que es todo el punto de simular, y `is_simulated = 1` (que ya trae la
        // fila desde `store_outbound_message()`) deja claro que el cliente nunca la recibió.
        if ($chat->last_inbound_simulated) {
            Log::channel('daily')->info('WhatsappChatHelper: respuesta del agente NO enviada, el chat está en modo simulación.', [
                'message_id' => $message->id,
                'chat_id'    => $chat->id,
            ]);

            self::mark_ai_message_sent($message, null);

            return self::ENVIO_HECHO;
        }

        try {
            $wa_message_id = (new WhatsappBotSendService())->send_text($chat->phone, (string) $message->body, $config);
        } catch (\Throwable $exception) {
            // Hoy `send_text()` se traga todas sus excepciones y devuelve null, pero si alguna
            // se le escapara la fila quedaría clavada en 'enviando' para siempre: nadie la
            // podría mandar ni descartar nunca más. Por eso el catch la devuelve a
            // 'a_confirmar' antes de propagar el resultado.
            self::mark_ai_message_send_failed($message, 'No se pudo enviar por WhatsApp: '.$exception->getMessage());

            return self::ENVIO_FALLIDO;
        }

        if (is_null($wa_message_id)) {
            // `send_text()` devuelve null cuando Kapso contestó con error o hubo una excepción
            // adentro del service. NO se puede marcar 'enviado': el cliente no recibió nada, y
            // un 'enviado' mentiroso entra al historial que ve la IA y la deja creyendo que ya
            // contestó el tema (nunca más vuelve sobre él).
            self::mark_ai_message_send_failed($message, 'WhatsApp rechazó el envío. Revisá la clave de Kapso y el número de la empresa, y volvé a intentarlo.');

            return self::ENVIO_FALLIDO;
        }

        self::mark_ai_message_sent($message, $wa_message_id);

        return self::ENVIO_HECHO;
    }

    /**
     * Gana el derecho a mandar una respuesta del agente, con una TRANSICIÓN DE ESTADO
     * CONDICIONAL en la base: pasa la fila de 'a_confirmar' a 'enviando' y solo devuelve
     * `true` si ese UPDATE afectó exactamente una fila.
     *
     * 🔴 ESTO NO ES UN `$message->save()` CON PASOS DE MÁS, Y NO SE PUEDE "SIMPLIFICAR" A UNO.
     * Un `save()` escribe siempre, sin preguntar en qué estado estaba la fila, así que dos
     * procesos que leyeron el mismo 'a_confirmar' ganarían los dos y el cliente recibiría el
     * mensaje dos veces. El `WHERE ai_status = 'a_confirmar'` es lo que hace que gane uno
     * solo: MySQL toma el lock de la fila durante el UPDATE y el segundo, cuando le toca, ya
     * la encuentra en 'enviando' y afecta 0 filas.
     *
     * El estado intermedio 'enviando' además es lo que protege al mensaje de que lo borren
     * mientras el POST a Kapso está en vuelo: los descartes solo borran filas en
     * 'a_confirmar' (ver `delete_pending_ai_messages()`).
     *
     * @param  WhatsappChatMessage  $message
     * @return bool  true si esta punta se quedó con el envío; false si lo agarró la otra.
     */
    private static function claim_ai_message_for_send(WhatsappChatMessage $message)
    {
        $claimed = WhatsappChatMessage::where('id', $message->id)
            ->where(function ($query) {
                $query->where('ai_status', 'a_confirmar')
                    // 🔴 RESCATE DE HUÉRFANOS. Sin esta rama, un worker que se muere de golpe
                    // (kill, reinicio del server, OOM) entre el claim y la marca deja la fila
                    // clavada en 'enviando' PARA SIEMPRE: no se puede mandar porque el claim
                    // exige 'a_confirmar', y no se puede descartar porque los borrados solo
                    // tocan 'a_confirmar'. Y lo peor no es el mensaje trabado: `has_unanswered_inbound()`
                    // lo cuenta como respondido, así que el agente NUNCA vuelve a contestarle a
                    // ese cliente. Pasados los minutos de STALE_SENDING_MINUTES no hay ningún
                    // proceso vivo que pueda estar enviándolo — el timeout hacia Kapso es de 15
                    // segundos —, así que tomarlo de nuevo es seguro y el sistema se recupera solo.
                    ->orWhere(function ($stale_query) {
                        $stale_query->where('ai_status', 'enviando')
                            ->where('updated_at', '<', now()->subMinutes(self::STALE_SENDING_MINUTES));
                    });
            })
            ->update(['ai_status' => 'enviando']);

        if ((int) $claimed !== 1) {
            return false;
        }

        // La transición fue por query builder, así que el modelo en memoria quedó viejo y hay
        // que ponerlo al día.
        //
        // 🔴 EL `syncOriginalAttribute()` NO SOBRA, aunque parezca ruido al lado de la línea de
        // arriba. Eloquent decide qué escribe un `save()` comparando el valor actual contra el
        // ORIGINAL con el que se cargó el modelo, que acá sigue siendo 'a_confirmar'. Sin
        // sincronizarlo, `mark_ai_message_send_failed()` pone 'a_confirmar' de vuelta, Eloquent
        // ve que coincide con el original, decide que no cambió nada y NO lo escribe: la fila
        // se queda clavada en 'enviando' para siempre, imposible de mandar y de descartar.
        // Pasó de verdad al probar esto.
        $message->ai_status = 'enviando';
        $message->syncOriginalAttribute('ai_status');

        return true;
    }

    /**
     * Marca como enviada una respuesta del agente: le carga el `wa_message_id` que devolvió
     * Kapso, le saca la fecha de auto-envío (ya no hay contador que mostrar) y la pasa a
     * `ai_status = 'enviado'`, que es el valor con el que entra al historial que ve la IA.
     *
     * Solo se llama desde `send_pending_ai_message()` y SIEMPRE después de haber ganado
     * `claim_ai_message_for_send()`. Por eso acá un `save()` común alcanza: con la fila en
     * 'enviando' nadie más la puede tomar ni borrar, así que no hay con quién correr.
     *
     * `delivery_status` NO se toca: sigue en `pendiente` hasta que llegue el evento de Meta.
     * Son dos ejes distintos ("¿lo aprobó un humano?" vs "¿lo entregó Meta?") y mezclarlos
     * rompería el rankeo anti-retroceso de `handle_delivery_status_event()`.
     *
     * @param  WhatsappChatMessage  $message  Mensaje con `ai_status = 'enviando'`.
     * @param  string|null  $wa_message_id  Id que devolvió Kapso al enviar.
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
     * Deja anotado que el envío se intentó y WhatsApp no lo aceptó: devuelve el mensaje a
     * 'a_confirmar' y lo marca con `delivery_status = 'fallido'` + el motivo en `send_error`,
     * que es como el resto del módulo representa un envío que no llegó
     * (ver `handle_delivery_status_event()` con el evento `whatsapp.message.failed`).
     *
     * Por qué vuelve a 'a_confirmar' y no queda en un estado terminal:
     *  - el cliente NO recibió nada, así que el mensaje sigue siendo algo que falta decir;
     *  - 'a_confirmar' es el único estado que el historial de la IA descarta
     *    (`WhatsappBotAiService::fetch_recent_messages()`), y es justo lo que hace falta acá:
     *    si contara como dicho, la IA nunca volvería sobre un tema que el cliente nunca leyó;
     *  - deja el mensaje reintentable: el operador ve el error y puede confirmarlo de nuevo.
     *
     * Igual que en `mark_ai_message_sent()`, acá alcanza un `save()`: se llega con la fila en
     * 'enviando', o sea con el envío ganado y sin nadie más pudiendo tocarla.
     *
     * @param  WhatsappChatMessage  $message  Mensaje con `ai_status = 'enviando'`.
     * @param  string  $error  Motivo, tal cual se lo muestra al operador.
     * @return WhatsappChatMessage
     */
    private static function mark_ai_message_send_failed(WhatsappChatMessage $message, $error)
    {
        $message->ai_status = 'a_confirmar';
        $message->delivery_status = 'fallido';
        $message->send_error = $error;
        // Sin fecha de auto-envío: el job de este mensaje ya corrió (o el operador ya lo
        // confirmó), así que no queda ningún temporizador que pueda vencer. Mostrar un
        // contador regresivo sería mentirle al operador.
        $message->ai_auto_send_at = null;
        $message->save();

        Log::channel('daily')->error('WhatsappChatHelper: falló el envío de una respuesta del agente, queda sin enviar.', [
            'message_id' => $message->id,
            'chat_id'    => $message->whatsapp_chat_id,
            'error'      => $error,
        ]);

        $chat = WhatsappChat::find($message->whatsapp_chat_id);
        if (! is_null($chat)) {
            self::broadcast_update((int) $chat->user_id, $chat, $message);
        }

        return $message;
    }

    /**
     * Borra las respuestas del agente que están esperando confirmación en un chat, cancelando
     * antes su job de auto-envío. Se llama cuando el cliente vuelve a escribir y cuando una
     * persona interviene en el chat (contesta a mano, manda una plantilla o apaga la IA).
     *
     * Se BORRAN, no se marcan (decisión D2 del plan), por dos motivos: el historial que ve la
     * IA leería un `direction = 'out'` que nunca se envió y creería que ya contestó; y el
     * operador vería en la conversación un mensaje que el cliente jamás recibió.
     *
     * @param  WhatsappChat  $chat
     * @return int  Cantidad de mensajes efectivamente borrados.
     */
    public static function discard_pending_ai_messages(WhatsappChat $chat)
    {
        $pending_ids = WhatsappChatMessage::where('whatsapp_chat_id', $chat->id)
            ->where('ai_status', 'a_confirmar')
            ->pluck('id');

        if ($pending_ids->isEmpty()) {
            return 0;
        }

        // Primero se cancelan los tokens y después se borra: al revés no serviría de nada,
        // porque `cancel_for_message()` bumpea un contador que vive en la propia fila. Esto es
        // lo que hace que un job de auto-envío que TODAVÍA NO ARRANCÓ se descarte solo.
        $auto_send_scheduler = new WhatsappAiAutoSendScheduler();
        foreach ($pending_ids as $pending_id) {
            $auto_send_scheduler->cancel_for_message((int) $pending_id);
        }

        $deleted = self::delete_pending_ai_messages($pending_ids->all());

        Log::channel('daily')->info('WhatsappChatHelper: respuestas del agente descartadas.', [
            'chat_id'     => $chat->id,
            'message_ids' => $pending_ids->all(),
            'borrados'    => $deleted,
        ]);

        // Sin mensaje: el front no puede "actualizar" una fila que ya no existe, tiene que
        // recargar los mensajes del chat. Se emite aunque no se haya borrado nada, porque el
        // `cancel_for_message()` de arriba ya le sacó la fecha de auto-envío al mensaje y el
        // contador que muestra el front quedó desactualizado igual.
        self::broadcast_update((int) $chat->user_id, $chat);

        return $deleted;
    }

    /**
     * Descarta UNA respuesta puntual del agente (endpoint `DELETE whatsapp-chats/messages/{id}`,
     * el "no la mandes" del operador), cancelando antes su job de auto-envío.
     *
     * @param  WhatsappChatMessage  $message
     * @return bool  false si no se borró porque el envío ya había arrancado.
     */
    public static function discard_pending_ai_message(WhatsappChatMessage $message)
    {
        (new WhatsappAiAutoSendScheduler())->cancel_for_message((int) $message->id);

        return self::delete_pending_ai_messages([(int) $message->id]) === 1;
    }

    /**
     * Borrado CONDICIONAL de respuestas pendientes: solo se van las filas que siguen en
     * 'a_confirmar'.
     *
     * 🔴 EL `WHERE ai_status = 'a_confirmar'` NO ES DECORATIVO Y NO SE PUEDE SACAR. Sin él,
     * un cliente que escribe justo cuando el job de auto-envío está en el POST a Kapso borra
     * la fila mientras el mensaje YA VA EN CAMINO: el cliente lo recibe, el `save()` posterior
     * del job actualiza cero filas sin quejarse, y el chat se queda sin ningún registro de lo
     * que se le dijo — con lo cual la próxima respuesta del agente se lo repite. Con el WHERE,
     * una fila que ya pasó a 'enviando' o 'enviado' sobrevive al descarte, que es lo correcto:
     * lo que ya salió no se puede deshacer.
     *
     * @param  array<int, int>  $message_ids
     * @return int  Cantidad de filas efectivamente borradas.
     */
    private static function delete_pending_ai_messages(array $message_ids)
    {
        if ($message_ids === []) {
            return 0;
        }

        return (int) WhatsappChatMessage::whereIn('id', $message_ids)
            ->where('ai_status', 'a_confirmar')
            ->delete();
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
            // Hereda la marca del chat: si el último entrante fue simulado, todo lo que salga
            // en respuesta también es parte de la simulación. Vale para la respuesta del
            // agente y para cualquier otra cosa que se mande en ese chat, así que la fila
            // nunca miente sobre si el cliente recibió algo o no.
            'is_simulated' => $chat->last_inbound_simulated ? 1 : 0,
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
