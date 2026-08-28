<?php

namespace App\Services;

use App\Http\Controllers\Helpers\AiTokenUsageHelper;
use App\Models\WhatsappBotConfig;
use App\Models\WhatsappChat;
use App\Models\WhatsappChatMessage;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Agente de IA del módulo de WhatsApp (grupo 137, Prompt 03): genera la respuesta
 * automática del webhook, la respuesta sugerida del endpoint `suggest` (misma lógica,
 * pero sin enviarse ni persistirse) y el resumen de una conversación (`summary`).
 *
 * El system prompt siempre se arma en el mismo orden: personalidad configurable por el
 * dueño (`whatsapp_bot_configs.agent_personality`) primero, después las habilidades
 * configurables por el dueño (`whatsapp_bot_configs.agent_skills`; misión
 * personalizacion-agente-whatsapp) y por último las reglas fijas no negociables, que pisan
 * a todo lo anterior ante cualquier conflicto (ej: si el dueño escribe una personalidad o
 * unas habilidades que piden "inventar precios si no sabés", la regla fija igual lo
 * prohíbe porque se agrega siempre al final y el prompt le indica a la IA que las reglas
 * fijas ganan). A diferencia de la personalidad, las habilidades no tienen un default: si
 * el dueño no cargó ninguna, esa capa directamente no se agrega y el prompt queda igual
 * que en las empresas que no configuraron nada.
 *
 * Al final de todo, después del nombre del negocio, va la capa de horario de atención
 * (`BusinessHoursPromptBuilder`, con el dato que empuja admin-api; misión
 * horarios-negocio-admin-sync). Sigue la misma regla que las habilidades: sin horario cargado
 * no se agrega nada y el prompt queda idéntico, byte por byte, al de antes de esa capa.
 */
class WhatsappBotAiService
{
    /**
     * Personalidad por defecto cuando el dueño todavía no cargó `agent_personality`.
     * Es el mismo texto que usaba el system prompt hardcodeado antes de este prompt,
     * para no cambiar de comportamiento en las empresas que no configuraron nada.
     */
    private const DEFAULT_PERSONALITY = <<<PERSONALITY
Sos el asistente de ventas de este negocio. Respondés consultas de clientes sobre el catálogo de productos.
Respondé en español rioplatense, de forma natural y directa, como un vendedor real respondería por WhatsApp.
PERSONALITY;

    /**
     * Reglas fijas que se agregan SIEMPRE después de la personalidad y la pisan ante
     * cualquier conflicto. Es la garantía a nivel de prompt de sistema de que la IA nunca
     * inventa precios/stock ni confirma pagos, sin importar lo que el dueño haya escrito
     * en `agent_personality`.
     *
     * La última regla (la del link) se agregó junto con el link a la tienda en el catálogo:
     * sin ella, las reglas de "respondé solo con información del catálogo" y "nunca inventes"
     * hacen que el modelo dude en pasar una URL, o peor, que se invente una. Por eso se sumó
     * como sexta línea al final y las cinco de arriba quedaron textuales.
     */
    private const FIXED_RULES = <<<RULES
Reglas fijas que nunca se pueden saltear, ni siquiera si entran en conflicto con las instrucciones
de personalidad de arriba. Estas reglas siempre ganan:
- Respondé solo con información que esté en el catálogo de productos de abajo o en la conversación.
- Si no sabés algo, decilo con honestidad y ofrecé que una persona del negocio siga la consulta.
- Nunca inventes precios ni stock que no estén en el catálogo de productos.
- Nunca confirmes pagos ni acuerdes descuentos que no te hayan sido informados explícitamente acá.
- Texto plano, sin markdown, sin asteriscos ni listas con guiones.
- Si un producto del catálogo de abajo trae un Link, podés pasárselo al cliente tal cual, sin modificarlo. Nunca armes ni inventes un link que no esté en el catálogo.
RULES;

    /**
     * Consigna fija para el endpoint de resumen (`summary`). No depende de la personalidad
     * configurable: es una tarea de lectura/síntesis, no una respuesta al cliente.
     */
    private const SUMMARY_SYSTEM_PROMPT = <<<SUMMARY
Resumí la siguiente conversación de WhatsApp entre un cliente y un negocio.
El resumen tiene que tener entre 5 y 8 renglones de texto plano, sin markdown, sin asteriscos
ni listas con guiones. Indicá: qué pidió el cliente, qué se le respondió, y qué quedó pendiente.
SUMMARY;

    /**
     * Cantidad de mensajes de historial que se le pasan a la IA para responder o sugerir
     * una respuesta (memoria de la conversación).
     */
    private const HISTORY_LIMIT = 20;

    /**
     * Cantidad de artículos del catálogo (RAG) que se incluyen en el mensaje del cliente.
     */
    private const RAG_LIMIT = 5;

    /**
     * Cantidad de mensajes de historial que se usan para el resumen del chat. Es más largo
     * que `HISTORY_LIMIT` porque acá no compite por espacio con el bloque de catálogo.
     */
    private const SUMMARY_HISTORY_LIMIT = 100;

    /**
     * Tope duro de imágenes entrantes que viajan al modelo cuando la visión está prendida:
     * las 3 más recientes del historial (D22).
     *
     * Sin tope, una conversación larga con fotos manda TODAS en cada turno: el gasto crece con
     * cada mensaje nuevo y la respuesta no mejora, porque lo que el cliente está preguntando
     * ahora casi siempre es sobre la última foto. El resto del historial sigue entrando como
     * texto, así que el modelo no pierde el hilo: pierde la vista de las fotos viejas.
     */
    private const VISION_IMAGE_LIMIT = 3;

    /**
     * Traducción del mime guardado al `media_type` que acepta la API de Anthropic.
     *
     * 🔴 NO ES LA LISTA BLANCA DE SEGURIDAD (esa es `WhatsappInboundMediaService::safe_mime()`,
     * y se sigue aplicando antes que esto). Es otra cosa: Anthropic acepta EXACTAMENTE cuatro
     * media types de imagen, y `image/jpg` NO es uno de ellos aunque sea un mime perfectamente
     * válido que el servicio guarda tal cual. Mandarlo hace que la API conteste 400, y el modo
     * de falla es el peor de todos: `generate_response()` se traga el error y devuelve '', o
     * sea que el síntoma no es un error visible sino "el agente dejó de contestar". Por eso se
     * normaliza acá y un mime que no esté en esta tabla degrada a texto en vez de viajar.
     *
     * @var array<string, string>
     */
    private const VISION_MEDIA_TYPES = [
        'image/jpeg' => 'image/jpeg',
        'image/jpg'  => 'image/jpeg',
        'image/png'  => 'image/png',
        'image/gif'  => 'image/gif',
        'image/webp' => 'image/webp',
    ];

    /**
     * Tope de bytes del archivo original de una imagen que viaja con visión.
     *
     * Anthropic corta en 5 MB por imagen, y lo que cuenta es el base64, que infla el archivo
     * un tercio: 3.932.160 bytes crudos son exactamente 5 MB codificados. Una imagen más
     * pesada que eso hace rebotar la llamada entera con 400 — y con `generate_response()`
     * tragándose el error, el negocio ve "el agente dejó de contestar" y no un error. Arriba
     * del tope, ese turno degrada a texto: el agente contesta igual, sin mirar la foto.
     *
     * @var int
     */
    private const VISION_MAX_IMAGE_BYTES = 3932160;

    /**
     * Marcador con el que el agente pide que se le adjunte la foto de un producto: el system
     * prompt le indica que cierre la respuesta con `[FOTO:<código de barras>]` en una línea
     * sola (ver `build_system_prompt()`).
     *
     * Está anclado en `$` porque la consigna es que vaya al final de todo: un `[FOTO:...]`
     * suelto en el medio del texto no es lo que se pidió, y sacarlo de ahí dejaría un hueco en
     * mitad de una oración. `[^\]\n]+` corta el código en el primer `]` o en el fin de línea,
     * así que un modelo que se olvide de cerrar el corchete se lleva puesta una línea y no
     * media respuesta.
     *
     * @var string
     */
    private const PHOTO_MARKER_PATTERN = '/\s*\[FOTO:([^\]\n]+)\]\s*$/';

    /**
     * Genera la respuesta del agente de IA para el chat dado: personalidad configurable +
     * reglas fijas + historial de la conversación (últimos 20 mensajes, con memoria real,
     * no solo el último mensaje) + catálogo relevante (RAG sobre artículos, top 5). La usan
     * tanto el webhook (respuesta automática que se envía) como el endpoint `suggest`
     * (respuesta sugerida que el operador edita antes de mandarla) — mismo servicio, mismo
     * resultado, la diferencia de qué se hace con el string queda del lado del caller.
     *
     * 🔴 EL TEXTO QUE SALE DE ACÁ YA VIENE SIN EL MARCADOR `[FOTO:...]`, y esa es la garantía
     * que hace que el cliente del negocio no lo pueda ver nunca. El motivo largo está en
     * `generate_response_with_photo()`, que es donde vive el recorte. Un caller que solo quiera
     * el texto usa este método y no tiene que saber que el marcador existe.
     *
     * @param WhatsappChat      $chat         Chat ya persistido (el mensaje que dispara la
     *                                        respuesta ya debe estar guardado en sus mensajes).
     * @param WhatsappBotConfig $config       Configuración activa del bot del owner del chat.
     * @param string            $proceso      Nombre con el que se imputa el gasto de tokens.
     *                                        Por default 'whatsapp_respuesta' (la automática
     *                                        del agente); el endpoint `suggest` manda
     *                                        'whatsapp_sugerencia' porque es la misma llamada
     *                                        HTTP pero con otro dueño del gasto.
     * @param int|null          $auth_user_id Persona que disparó la llamada, o null si la
     *                                        disparó un job automático.
     *
     * @return string Respuesta generada y ya limpia, o cadena vacía si hubo un error o no hay
     *                historial.
     */
    public function generate_response(WhatsappChat $chat, WhatsappBotConfig $config, $proceso = 'whatsapp_respuesta', $auth_user_id = null): string
    {
        $resultado = $this->generate_response_with_photo($chat, $config, $proceso, $auth_user_id);

        return $resultado['body'];
    }

    /**
     * Lo mismo que `generate_response()`, pero devolviendo aparte el código de barras que el
     * agente marcó con `[FOTO:...]`. Lo usa el único consumidor que puede hacer algo con ese
     * código: `GenerateWhatsappAiReplyJob`, que resuelve el artículo del catálogo y adjunta la
     * foto a la respuesta que persiste.
     *
     * 🔴 EL RECORTE DEL MARCADOR VIVE ACÁ, EN EL ÚNICO LUGAR QUE PRODUCE EL TEXTO, Y NO EN
     * NINGUNO DE SUS CONSUMIDORES. Antes vivía adentro de `GenerateWhatsappAiReplyJob`, o sea
     * en UNO de los dos caminos, y el otro —`WhatsappChatController@suggest()`, el botón
     * "Sugerir respuesta"— devolvía el texto crudo: al operador le caía en el input un borrador
     * que terminaba en `[FOTO:7791234567890]` y, si lo mandaba sin leer hasta abajo, eso le
     * llegaba al cliente por WhatsApp. Y encima por ese camino el marcador no servía para nada,
     * porque `send_message()` manda texto y nunca adjunta una foto: era basura de
     * implementación pura, a la vista del cliente del negocio.
     *
     * Si alguien vuelve a mover el recorte a un solo camino, el bug vuelve tal cual y vuelve
     * silencioso: el agente automático —que es lo que se prueba primero— sigue andando perfecto,
     * y lo que se rompe es el OTRO consumidor. Peor todavía, cada consumidor nuevo que aparezca
     * (otro endpoint, otro job, una integración) nace con la fuga puesta y nadie se entera hasta
     * que la ve un cliente. Con el recorte en el productor, un consumidor nuevo nace limpio sin
     * hacer nada, porque afuera de este service el marcador directamente no existe.
     *
     * La forma del par también es a propósito: el método corto y de nombre obvio
     * (`generate_response()`) es el seguro, y el que devuelve el código de barras es el que hay
     * que ir a buscar. El camino fácil tiene que ser el que no rompe nada.
     *
     * @param WhatsappChat      $chat         Chat ya persistido.
     * @param WhatsappBotConfig $config       Configuración activa del bot del owner del chat.
     * @param string            $proceso      Nombre con el que se imputa el gasto de tokens.
     * @param int|null          $auth_user_id Persona que disparó la llamada, o null si la
     *                                        disparó un job automático.
     *
     * @return array{body: string, bar_code: string} `body` es el texto ya sin marcador;
     *                                               `bar_code` es el código que marcó el agente,
     *                                               o cadena vacía si no marcó ninguno.
     */
    public function generate_response_with_photo(WhatsappChat $chat, WhatsappBotConfig $config, $proceso = 'whatsapp_respuesta', $auth_user_id = null): array
    {
        try {
            $api_key = (string) config('services.anthropic.api_key');
            if ($api_key === '') {
                Log::channel('daily')->warning('WhatsappBotAiService: ANTHROPIC_API_KEY no configurada.');
                return $this->empty_response();
            }

            // Últimos N mensajes del chat, en orden cronológico (el más viejo primero).
            $history = $this->fetch_recent_messages($chat, self::HISTORY_LIMIT);
            if ($history->isEmpty()) {
                return $this->empty_response();
            }

            // El RAG se dispara sobre TODOS los mensajes del cliente que quedaron sin
            // responder, no solo sobre el último: si mandó "hola", "tenés tornillos" y
            // "de 3 pulgadas" en tres mensajes seguidos, buscar en el catálogo solo por el
            // último se lleva puesto el producto que estaba preguntando.
            $pending_customer_messages = $this->pending_customer_messages_body($history);

            $articles = [];
            if ($pending_customer_messages !== '') {
                $embedding_service = new ArticleEmbeddingService();
                $articles = $embedding_service
                    ->search_similar_articles($pending_customer_messages, (int) $chat->user_id, self::RAG_LIMIT)
                    ->all();
            }

            $system_prompt = $this->build_system_prompt($config);
            // El `$config` viaja hasta el armado del payload porque ahí se decide si las
            // imágenes entrantes van como bloques de visión o como texto (`ai_vision_enabled`).
            $messages = $this->build_messages_payload($history, $articles, $this->owner_user($config), $config);

            $model = (string) config('services.anthropic.model', 'claude-sonnet-4-20250514');
            $http  = $this->build_http_client();

            $response = $http->post('https://api.anthropic.com/v1/messages', [
                'model'      => $model,
                'max_tokens' => 500,
                'system'     => $system_prompt,
                'messages'   => $messages,
            ]);

            if ($response->failed()) {
                Log::channel('daily')->error('WhatsappBotAiService: error HTTP de Anthropic.', [
                    'status' => $response->status(),
                    'body'   => substr($response->body(), 0, 500),
                ]);
                // Sin respuesta válida no hay consumo que imputar: se sale antes de registrar.
                return $this->empty_response();
            }

            $body = $response->json();

            // El registro va acá, con el body crudo todavía en la mano: `extract_text()` se
            // queda solo con el texto y descarta el bloque `usage`, así que después de esa
            // línea ya no queda de dónde sacar los contadores de tokens.
            AiTokenUsageHelper::registrar([
                'user_id'       => (int) $chat->user_id,
                'proceso'       => $proceso,
                'body'          => is_array($body) ? $body : [],
                'modelo'        => $model,
                'auth_user_id'  => $auth_user_id,
                'referencia_id' => (int) $chat->id,
            ]);

            return $this->split_photo_marker($this->extract_text($body));
        } catch (\Throwable $exception) {
            Log::channel('daily')->error('WhatsappBotAiService: excepción al generar respuesta.', [
                'chat_id' => $chat->id,
                'error'   => $exception->getMessage(),
            ]);
            return $this->empty_response();
        }
    }

    /**
     * Parte la respuesta cruda del modelo en el cuerpo que va a leer una persona y el código de
     * barras que el agente marcó con `[FOTO:...]`.
     *
     * 🔴 EL MARCADOR SE SACA SIEMPRE, PASE LO QUE PASE. Este método no sabe si el artículo
     * existe, si tiene foto cargada ni si el texto entra en un epígrafe, y no le importa: la
     * degradación de la foto es "sale como texto normal", NUNCA "sale con el marcador a la
     * vista". Quien decide si la foto se adjunta es el job, y decide sobre el `bar_code` que
     * sale de acá, con el cuerpo ya limpio en la mano — así ningún camino de falla de la foto
     * (código inventado, artículo sin imagen, texto largo, base caída) puede devolver el
     * marcador al texto.
     *
     * El recorte es un `substr` hasta donde arranca el marcador y no un segundo `preg_replace`,
     * justamente porque acá no puede fallar nada: `PREG_OFFSET_CAPTURE` ya devolvió la posición
     * exacta en la misma pasada que encontró el marcador, así que cortar es aritmética y no hay
     * una segunda ejecución del motor de expresiones que pueda devolver null y dejarme sin
     * cuerpo. Los offsets son en bytes igual que `substr`, y `trim` solo saca espacios ASCII,
     * así que no parte un carácter multibyte.
     *
     * @param string $texto Respuesta cruda del modelo, con marcador o sin él.
     *
     * @return array{body: string, bar_code: string} `bar_code` vacío = el modelo no marcó
     *                                               ninguna foto, que es el 100% de las
     *                                               respuestas de antes de esta funcionalidad.
     */
    private function split_photo_marker(string $texto): array
    {
        // Sin marcador no se toca nada: el texto sale byte por byte como lo devolvió el modelo.
        if (! preg_match(self::PHOTO_MARKER_PATTERN, $texto, $matches, PREG_OFFSET_CAPTURE)) {
            return ['body' => $texto, 'bar_code' => ''];
        }

        return [
            'body'     => trim(substr($texto, 0, $matches[0][1])),
            'bar_code' => trim((string) $matches[1][0]),
        ];
    }

    /**
     * "No hay respuesta", con la forma que devuelve `generate_response_with_photo()`.
     *
     * Existe para que los seis cortes tempranos de ese método (sin API key, historial vacío,
     * error HTTP, excepción) no tengan que repetir el literal: si mañana el par gana un tercer
     * campo, se agrega en un solo lugar y ninguna rama se queda sin él.
     *
     * @return array{body: string, bar_code: string}
     */
    private function empty_response(): array
    {
        return ['body' => '', 'bar_code' => ''];
    }

    /**
     * Genera un resumen de la conversación (últimos 100 mensajes) para el endpoint
     * `summary`. Es una llamada aparte e independiente de `generate_response()`: no usa
     * personalidad, reglas fijas ni catálogo, solo una consigna fija de resumen. No
     * persiste nada — el caller solo devuelve el string al front.
     *
     * @param WhatsappChat $chat         Chat a resumir.
     * @param string       $proceso      Nombre con el que se imputa el gasto de tokens.
     * @param int|null     $auth_user_id Persona que pidió el resumen, o null si lo disparó
     *                                   un job automático.
     *
     * @return string Resumen generado, o cadena vacía si hubo un error o no hay mensajes.
     */
    public function generate_summary(WhatsappChat $chat, $proceso = 'whatsapp_resumen', $auth_user_id = null): string
    {
        try {
            $api_key = (string) config('services.anthropic.api_key');
            if ($api_key === '') {
                Log::channel('daily')->warning('WhatsappBotAiService: ANTHROPIC_API_KEY no configurada (resumen).');
                return '';
            }

            $history = $this->fetch_recent_messages($chat, self::SUMMARY_HISTORY_LIMIT);
            if ($history->isEmpty()) {
                return '';
            }

            $transcript = $this->build_transcript($history);
            if ($transcript === '') {
                return '';
            }

            $model = (string) config('services.anthropic.model', 'claude-sonnet-4-20250514');
            $http  = $this->build_http_client();

            $response = $http->post('https://api.anthropic.com/v1/messages', [
                'model'      => $model,
                'max_tokens' => 400,
                'system'     => self::SUMMARY_SYSTEM_PROMPT,
                'messages'   => [
                    ['role' => 'user', 'content' => "Conversación:\n{$transcript}"],
                ],
            ]);

            if ($response->failed()) {
                Log::channel('daily')->error('WhatsappBotAiService: error HTTP de Anthropic (resumen).', [
                    'status' => $response->status(),
                    'body'   => substr($response->body(), 0, 500),
                ]);
                // Sin respuesta válida no hay consumo que imputar: se sale antes de registrar.
                return '';
            }

            $body = $response->json();

            // Mismo motivo que en generate_response(): el `usage` se lee del body crudo,
            // antes de que `extract_text()` se quede solo con el texto.
            AiTokenUsageHelper::registrar([
                'user_id'       => (int) $chat->user_id,
                'proceso'       => $proceso,
                'body'          => is_array($body) ? $body : [],
                'modelo'        => $model,
                'auth_user_id'  => $auth_user_id,
                'referencia_id' => (int) $chat->id,
            ]);

            return $this->extract_text($body);
        } catch (\Throwable $exception) {
            Log::channel('daily')->error('WhatsappBotAiService: excepción al generar resumen.', [
                'chat_id' => $chat->id,
                'error'   => $exception->getMessage(),
            ]);
            return '';
        }
    }

    /**
     * Trae los últimos `$limit` mensajes del chat, en orden cronológico (el más viejo
     * primero), tal como los necesita el payload de `messages` de Anthropic.
     *
     * Quedan afuera los mensajes con `ai_status = 'a_confirmar'`: son respuestas generadas
     * que todavía esperan el visto bueno de una persona, o sea que el cliente nunca las
     * recibió. Si entraran al historial, la IA leería un 'out' que no existe para el
     * cliente y creería que ya contestó. Cuando se envían pasan a 'enviado' y recién ahí
     * aparecen acá.
     *
     * @param WhatsappChat $chat
     * @param int          $limit
     *
     * @return \Illuminate\Support\Collection<int, WhatsappChatMessage>
     */
    private function fetch_recent_messages(WhatsappChat $chat, int $limit)
    {
        return WhatsappChatMessage::where('whatsapp_chat_id', $chat->id)
            ->where(function ($query) {
                $query->whereNull('ai_status')->orWhere('ai_status', '!=', 'a_confirmar');
            })
            ->orderBy('created_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values();
    }

    /**
     * Junta el texto de todos los mensajes entrantes ('in', del cliente) consecutivos que
     * quedaron sin responder: recorre el historial de atrás para adelante y corta apenas se
     * topa con el primer 'out'. Es el texto que dispara la búsqueda RAG.
     *
     * No alcanza con quedarse con el último 'in' (que es lo que hacía antes): cuando el
     * cliente escribe "hola", "tenés tornillos" y "de 3 pulgadas" en tres mensajes seguidos,
     * el agente los contesta de una sola vez, así que el catálogo tiene que buscarse por los
     * tres juntos. Con solo el último, la consulta al RAG queda sin el producto.
     *
     * Que corte en el primer 'out' es a propósito: si el operador ya contestó a mano, no hay
     * nada pendiente y no tiene sentido traer catálogo para una consulta ya respondida.
     *
     * @param \Illuminate\Support\Collection<int, WhatsappChatMessage> $history_messages
     *
     * @return string Vacío si el historial no termina en mensajes del cliente.
     */
    private function pending_customer_messages_body($history_messages): string
    {
        $bodies = [];

        for ($index = $history_messages->count() - 1; $index >= 0; $index--) {
            $message = $history_messages->get($index);

            if ($message->direction !== 'in') {
                break;
            }

            $body = trim((string) $message->body);
            if ($body !== '') {
                // Se recorre al revés, así que cada uno se apila adelante para devolverlos
                // en el orden en que el cliente los escribió.
                array_unshift($bodies, $body);
            }
        }

        if (count($bodies) > 0) {
            return implode("\n", $bodies);
        }

        // Repliegue: no hay entrantes sin responder porque el último mensaje del chat es
        // saliente. El agente automático nunca llega acá (su job se aborta si no hay nada
        // sin responder), pero el botón "Sugerir respuesta" SÍ: el operador lo puede tocar
        // después de haber contestado a mano. Sin este repliegue el RAG se quedaba sin
        // texto de búsqueda y la IA respondía con el catálogo vacío, o sea que agrupar los
        // mensajes habría roto una función que ya andaba. Se cae al último entrante del
        // historial, que es exactamente lo que se usaba antes de la agrupación.
        for ($index = $history_messages->count() - 1; $index >= 0; $index--) {
            $message = $history_messages->get($index);

            if ($message->direction !== 'in') {
                continue;
            }

            $body = trim((string) $message->body);
            if ($body !== '') {
                return $body;
            }
        }

        return '';
    }

    /**
     * Arma el system prompt final: personalidad configurable (o la default) primero,
     * después las habilidades configurables (si el dueño cargó alguna), reglas fijas no
     * negociables después, la consigna del marcador de foto, y por último el nombre del
     * negocio como contexto adicional.
     *
     * 🔴 LA CONSIGNA DE LA FOTO VA DESPUÉS DE LAS REGLAS FIJAS Y NO ADENTRO DE ELLAS, aunque
     * las reglas fijas serían el lugar obvio. Dos motivos: una de esas reglas dice "texto
     * plano, sin markdown", y el marcador es exactamente lo contrario —un token con corchetes
     * que el modelo tiene que escribir literal—, así que meterlo en la misma lista lo deja
     * peleando contra su vecina de arriba; y este bloque explica un mecanismo del sistema
     * (algo que se saca del mensaje antes de mandarlo), no una restricción de qué se le puede
     * decir al cliente, que es de lo que hablan las reglas fijas.
     *
     * ⚠️ QUE EL MARCADOR NO SALGA NUNCA NO DEPENDE DE ESTE TEXTO. El prompt es una pedida, no
     * una garantía: el modelo puede escribir el marcador cuando no corresponde, escribirlo con
     * un código inventado, o no escribirlo nunca. Quien garantiza que el cliente jamás vea un
     * `[FOTO:...]` es `generate_response_with_photo()`, que lo saca del cuerpo SIEMPRE, exista o
     * no el artículo. Acá solo se le pide que lo ponga cuando tiene sentido.
     *
     * Por eso mismo la consigna se le manda igual a los dos consumidores y no solo al agente
     * automático: la sugerencia para el operador tiene que ser LA MISMA respuesta que habría
     * dado el agente (es lo que promete `suggest()`), y de todas formas el marcador nunca
     * sobrevive a la salida del service. Gatearlo por consumidor sería fingir una garantía que
     * no da el prompt y hacer que las dos respuestas dejen de ser comparables.
     *
     * @param WhatsappBotConfig $config
     *
     * @return string
     */
    private function build_system_prompt(WhatsappBotConfig $config): string
    {
        $personality = trim((string) $config->agent_personality);
        if ($personality === '') {
            $personality = self::DEFAULT_PERSONALITY;
        }

        // El marcador se pide en una línea sola y al final a propósito: así el texto que le
        // queda al cliente después de sacarlo termina prolijo, sin un corte en medio de una
        // oración. Y se aclara que no lo mencione, porque si no el modelo tiende a explicarle
        // al cliente que "te mando la foto con el código", que es ruido de implementación.
        $photo_rule = 'Si estás recomendando UN producto puntual del catálogo de abajo, terminá tu respuesta con'
            .' una última línea que diga exactamente [FOTO:<código de barras del producto>], sin ningún otro'
            .' texto en esa línea.'
            ."\n"
            .'Esa línea es una marca interna del sistema: se saca del mensaje antes de mandárselo al cliente y'
            .' sirve para adjuntarle la foto del producto. No la menciones ni la expliques en el texto.'
            ."\n"
            .'Poné como mucho una por respuesta, y solo si estás recomendando un producto puntual: si estás'
            .' listando varios, o no estás recomendando ninguno, no la pongas.';

        $blocks = [$personality];

        // Habilidades del agente IA (rubro, vocabulario, qué preguntar), texto libre del
        // dueño. A diferencia de la personalidad NO tiene default: si viene vacío no se
        // agrega nada al array, y el prompt queda idéntico al de antes de esta capa (garantía
        // de no-regresión para las empresas que no configuren nada).
        $skills = trim((string) $config->agent_skills);
        if ($skills !== '') {
            $blocks[] = $skills;
        }

        $blocks[] = self::FIXED_RULES;
        $blocks[] = $photo_rule;

        $business_name = $this->business_name($config);
        if ($business_name !== '') {
            $blocks[] = 'Nombre del negocio: '.$business_name.'.';
        }

        // Capa de contexto: horario del negocio empujado por admin-api (misión horarios-negocio-admin-sync).
        // Mismo criterio que agent_skills: si no hay dato, la capa NO se agrega y el prompt queda idéntico
        // al de antes. El horario es de la INSTANCIA (lo escribe solo el admin), por eso si el user del bot
        // no tiene fila propia se cae a la del owner de la instancia.
        $horarios = BusinessHoursReader::for_user_o_instancia((int) $config->user_id);

        $capa_horario = (new BusinessHoursPromptBuilder())->capa_de_horario($horarios);
        if ($capa_horario !== '') {
            $blocks[] = $capa_horario;
        }

        return implode("\n\n", $blocks);
    }

    /**
     * Nombre del negocio (dueño del bot), usado como contexto adicional del system prompt.
     *
     * @param WhatsappBotConfig $config
     *
     * @return string Vacío si no se pudo resolver el usuario o no tiene nombre cargado.
     */
    private function business_name(WhatsappBotConfig $config): string
    {
        $owner_user = $this->owner_user($config);

        return trim((string) ($owner_user->name ?? ''));
    }

    /**
     * Usuario dueño del bot (el negocio). Se resuelve una sola vez y se reusa: hoy lo
     * necesitan el nombre del negocio en el system prompt y la URL de la tienda (`online`)
     * de cada línea del catálogo. Si cada uno lo pidiera por su lado serían dos queries
     * para traer la misma fila, por eso la carga vive acá y no en cada consumidor.
     *
     * @param WhatsappBotConfig $config
     *
     * @return \App\Models\User|null Null si la config quedó huérfana de usuario.
     */
    private function owner_user(WhatsappBotConfig $config)
    {
        if (! $config->relationLoaded('user')) {
            $config->load('user');
        }

        return $config->user;
    }

    /**
     * Convierte el historial de mensajes y los artículos del RAG en el array `messages`
     * que espera la API de Anthropic. Mapea `direction` a rol ('in' => user, 'out' =>
     * assistant) y funde turnos consecutivos del mismo rol en uno solo, porque la API
     * exige que los turnos alternen estrictamente y el historial real puede tener dos
     * mensajes 'in' o dos 'out' seguidos (ej: el cliente manda dos mensajes seguidos, o el
     * operador contesta y después lo hace también la IA). El bloque de catálogo se agrega
     * siempre al final del último turno de usuario para no romper la alternancia.
     *
     * 🔴 EL CONTENIDO DE UN TURNO PUEDE SER UN STRING O UN ARRAY DE BLOQUES, Y ESA ES LA PARTE
     * MÁS FÁCIL DE ROMPER DE TODO EL BACKEND (D23). Cuando la visión está prendida y el turno
     * trae una imagen, el contenido deja de ser texto y pasa a ser
     * `[{type:image,source:{...}}, {type:text,text:…}]`, que es lo que la API de Anthropic
     * acepta. La fusión de turnos consecutivos —que ANTES era un `.=` sobre un string— revienta
     * con eso: concatenar un array con un string es un fatal. Por eso toda la unión de contenido
     * pasa ahora por `merge_turn_contents()`, que mantiene el `.=` de siempre cuando los dos
     * lados son string y concatena bloques cuando alguno no lo es. Lo mismo vale para el bloque
     * de catálogo que se pega al final del último turno de usuario: es otro `.=` y falla igual.
     *
     * Y el modo de falla no se ve: `generate_response()` se traga el `\Throwable` y devuelve
     * '', así que lo que ve el negocio no es un error sino "el agente dejó de contestar" en
     * cualquier conversación con dos imágenes seguidas.
     *
     * @param \Illuminate\Support\Collection<int, WhatsappChatMessage> $history_messages
     * @param array                                                    $articles
     * @param \App\Models\User|null                                    $owner_user Dueño del
     *                                        bot; se usa para armar el link a su tienda online.
     * @param WhatsappBotConfig|null                                   $config Config del bot;
     *                                        de acá sale `ai_vision_enabled`. Null = sin visión
     *                                        (o sea, exactamente el comportamiento previo).
     *
     * @return array<int, array{role: string, content: string|array}>
     */
    private function build_messages_payload($history_messages, array $articles, $owner_user = null, $config = null): array
    {
        // Con el interruptor apagado (el default) no se lee un solo archivo del disco y el
        // payload sale idéntico al de antes de esta misión: la imagen entra como el texto de su
        // epígrafe (o el literal '[Imagen recibida]'), el modelo se entera de que llegó una foto
        // y no se gasta un token de visión.
        $vision_message_ids = [];
        if (! is_null($config) && (bool) $config->ai_vision_enabled) {
            $vision_message_ids = $this->recent_vision_message_ids($history_messages);
        }

        $turns = [];

        foreach ($history_messages as $message) {
            $body = trim((string) $message->body);

            $content = null;
            if (in_array((int) $message->id, $vision_message_ids, true)) {
                // Devuelve null si el archivo no está, no se puede leer o el mime no le sirve a
                // Anthropic: ese turno degrada a texto y la conversación sigue.
                $content = $this->build_vision_content($message, $body);
            }

            if (is_null($content)) {
                if ($body === '') {
                    continue;
                }
                $content = $body;
            }

            $role = $message->direction === 'in' ? 'user' : 'assistant';
            $last_index = count($turns) - 1;

            if ($last_index >= 0 && $turns[$last_index]['role'] === $role) {
                // Mismo rol que el turno anterior: se funde en un solo mensaje.
                $turns[$last_index]['content'] = $this->merge_turn_contents($turns[$last_index]['content'], $content, "\n");
            } else {
                $turns[] = ['role' => $role, 'content' => $content];
            }
        }

        // La conversación tiene que arrancar en 'user' para la API de Anthropic; si el
        // historial (recortado a los últimos N) arranca con un turno 'assistant', se
        // descarta para no romper la alternancia.
        while (! empty($turns) && $turns[0]['role'] !== 'user') {
            array_shift($turns);
        }

        $catalog_block = $this->build_catalog_block($articles, $owner_user);
        $last_index = count($turns) - 1;

        if ($last_index >= 0 && $turns[$last_index]['role'] === 'user') {
            // Mismo cuidado que en la fusión de arriba: si el último turno terminó siendo
            // bloques (porque el cliente mandó una foto como último mensaje, que es el caso
            // NORMAL cuando la visión está prendida), este `.=` sería un fatal.
            $turns[$last_index]['content'] = $this->merge_turn_contents($turns[$last_index]['content'], $catalog_block, "\n\n");
        } else {
            // No hay ningún turno de usuario (caso borde: historial vacío tras el filtro
            // de arriba); se agrega el catálogo como único mensaje para poder llamar a la API.
            $turns[] = ['role' => 'user', 'content' => $catalog_block];
        }

        return $turns;
    }

    /**
     * Ids de los mensajes entrantes con imagen que van a viajar como bloques de visión: los
     * `VISION_IMAGE_LIMIT` más recientes del historial (D22).
     *
     * Se recorre de atrás para adelante y se corta al llegar al tope. Los candidatos se eligen
     * mirando solo las columnas (`direction`, `media_type`, `media_path`), sin tocar el disco:
     * si después el archivo no se puede leer, ese turno degrada a texto y NO se promueve un
     * cuarto candidato en su lugar. Es a propósito — el tope es de fotos que el cliente mandó
     * recién, no de fotos que efectivamente se pudieron cargar, y así el resultado no depende
     * del estado del disco.
     *
     * @param \Illuminate\Support\Collection<int, WhatsappChatMessage> $history_messages
     *
     * @return array<int, int>
     */
    private function recent_vision_message_ids($history_messages): array
    {
        $ids = [];

        for ($index = $history_messages->count() - 1; $index >= 0; $index--) {
            if (count($ids) >= self::VISION_IMAGE_LIMIT) {
                break;
            }

            $message = $history_messages->get($index);

            if ($message->direction !== 'in') {
                continue;
            }
            if ((string) $message->media_type !== 'image') {
                continue;
            }
            if (trim((string) $message->media_path) === '') {
                continue;
            }

            $ids[] = (int) $message->id;
        }

        return $ids;
    }

    /**
     * Arma el contenido de un turno como bloques, con la imagen en base64 adelante y el texto
     * (el epígrafe, o el literal de relleno) atrás.
     *
     * 🔴 LA IMAGEN VIAJA EN BASE64 Y NO POR URL, y no es por gusto: el archivo vive en el disco
     * `local`, fuera del docroot, justamente para que una conversación privada no quede abierta
     * a cualquiera que adivine una URL. No hay URL que Anthropic pueda bajar, y crear una sería
     * deshacer esa decisión.
     *
     * 🔴 NUNCA LANZA. Cualquier problema (archivo borrado, disco caído, mime que la API no
     * acepta, imagen demasiado pesada) devuelve null y el turno sigue viaje como texto. Una
     * excepción acá sería el agente entero mudo: `generate_response()` la atrapa y devuelve '',
     * o sea que el negocio vería "dejó de contestar" sin ningún error a la vista.
     *
     * @param WhatsappChatMessage $message
     * @param string              $body  Cuerpo ya trimeado del mensaje.
     *
     * @return array|null Array de bloques, o null para que el turno degrade a texto.
     */
    private function build_vision_content(WhatsappChatMessage $message, string $body): ?array
    {
        try {
            // La misma lista blanca con la que se guardó el archivo y con la que se sirve.
            $mime = WhatsappInboundMediaService::safe_mime($message->media_mime);
            if (is_null($mime) || ! isset(self::VISION_MEDIA_TYPES[$mime])) {
                return null;
            }

            $path = trim((string) $message->media_path);
            $disk = Storage::disk('local');

            if ($path === '' || ! $disk->exists($path)) {
                return null;
            }

            if ((int) $disk->size($path) > self::VISION_MAX_IMAGE_BYTES) {
                Log::channel('daily')->warning('WhatsappBotAiService: imagen demasiado pesada para visión, va como texto.', [
                    'message_id' => $message->id,
                    'path'       => $path,
                ]);

                return null;
            }

            $binary = $disk->get($path);
            if ($binary === null || $binary === '') {
                return null;
            }

            $blocks = [[
                'type'   => 'image',
                'source' => [
                    'type'       => 'base64',
                    'media_type' => self::VISION_MEDIA_TYPES[$mime],
                    'data'       => base64_encode($binary),
                ],
            ]];

            // El texto va DESPUÉS de la imagen: es el orden que recomienda Anthropic para que el
            // modelo lea la consigna con la foto ya vista. Si el mensaje no trae texto, el
            // bloque de imagen viaja solo, que la API acepta sin problema.
            if ($body !== '') {
                $blocks[] = ['type' => 'text', 'text' => $body];
            }

            return $blocks;
        } catch (\Throwable $exception) {
            Log::channel('daily')->error('WhatsappBotAiService: no se pudo adjuntar la imagen al turno, sigue como texto.', [
                'message_id' => $message->id,
                'error'      => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Une el contenido de dos turnos consecutivos del mismo rol (D23).
     *
     * 🔴 LA REGLA, Y NO SE PUEDE SIMPLIFICAR A UN `.=`: si los dos lados son string se
     * concatenan como siempre (el comportamiento previo, byte por byte); si alguno de los dos
     * es un array de bloques, los dos se normalizan a bloques y se concatenan. Un `.=` con un
     * array de un lado es un fatal, y con `generate_response()` tragándose la excepción el
     * síntoma sería "el agente dejó de contestar" en toda conversación con dos imágenes
     * seguidas — sin un solo error visible.
     *
     * @param string|array $current   Contenido que ya tenía el turno.
     * @param string|array $incoming  Contenido que se le suma.
     * @param string       $separator Separador entre textos, solo cuando los dos son string.
     *
     * @return string|array
     */
    private function merge_turn_contents($current, $incoming, string $separator)
    {
        if (is_string($current) && is_string($incoming)) {
            return $current.$separator.$incoming;
        }

        return array_merge($this->as_content_blocks($current), $this->as_content_blocks($incoming));
    }

    /**
     * Normaliza un contenido de turno a array de bloques: si ya lo es, lo devuelve tal cual;
     * si es texto, lo envuelve en un bloque `text`.
     *
     * @param string|array $content
     *
     * @return array<int, array>
     */
    private function as_content_blocks($content): array
    {
        if (is_array($content)) {
            return $content;
        }

        $text = (string) $content;

        return trim($text) === '' ? [] : [['type' => 'text', 'text' => $text]];
    }

    /**
     * Arma el bloque de texto del catálogo relevante (RAG) con nombre, precio y stock de
     * cada artículo, tal como lo consume el system/user prompt.
     *
     * @param array                 $articles   Artículos devueltos por `search_similar_articles`
     *                                          (stdClass).
     * @param \App\Models\User|null $owner_user Dueño del bot, para el link a su tienda online.
     *
     * @return string
     */
    private function build_catalog_block(array $articles, $owner_user = null): string
    {
        if (empty($articles)) {
            return 'Productos del catálogo más relevantes para la consulta: no se encontraron productos relevantes.';
        }

        $lines = [];
        foreach ($articles as $article) {
            $lines[] = $this->format_article_line($article, $owner_user);
        }

        return "Productos del catálogo más relevantes para la consulta:\n".implode("\n", $lines);
    }

    /**
     * Formatea un artículo del RAG como línea de texto plano ("- nombre | Precio: $x |
     * Stock: y | Link: https://..."), aceptando tanto stdClass (lo que devuelve
     * `search_similar_articles`) como array, de forma defensiva.
     *
     * @param array|object          $article
     * @param \App\Models\User|null $owner_user Dueño del bot, para el link a su tienda online.
     *
     * @return string
     */
    private function format_article_line($article, $owner_user = null): string
    {
        $name  = (string) $this->article_field($article, 'name', '');
        $price = $this->article_field($article, 'final_price', null);
        $stock = $this->article_field($article, 'stock', null);

        $line = '- '.$name;

        if ($price !== null && $price !== '') {
            $line .= ' | Precio: $'.number_format((float) $price, 2, ',', '.');
        }

        if ($stock !== null && $stock !== '') {
            $line .= ' | Stock: '.(int) $stock;
        }

        $url = $this->article_url($article, $owner_user);
        if ($url !== '') {
            $line .= ' | Link: '.$url;
        }

        // 🔴 SIN ESTE CAMPO LA FOTO DEL PRODUCTO NO SE PUEDE MANDAR NUNCA. El system prompt le
        // pide al modelo que cierre con `[FOTO:<código de barras>]`, y `GenerateWhatsappAiReplyJob`
        // resuelve el artículo por `bar_code`. Si la línea del catálogo no trae el código, el
        // modelo no tiene de dónde sacarlo: o se lo inventa (y no resuelve nada) o no escribe el
        // marcador. La consulta del RAG ya lo selecciona desde siempre
        // (`ArticleEmbeddingService::search_similar_articles`), pero se perdía acá.
        //
        // Va último a propósito: lo primero que lee el modelo tiene que ser lo que le sirve al
        // cliente (nombre, precio, stock, link). El código es maquinaria interna.
        $bar_code = trim((string) $this->article_field($article, 'bar_code', ''));
        if ($bar_code !== '') {
            $line .= ' | Código: '.$bar_code;
        }

        return $line;
    }

    /**
     * Arma el link público del artículo en la tienda online del negocio, con el mismo
     * formato que usa App\Mail\Advise.
     *
     * OJO: a propósito NO se replica la condición `APP_ENV == 'production'` que tiene el
     * mail, y no hay que "corregirlo" agregándola:
     * (a) en el mail la condición existe porque una campaña disparada desde una máquina de
     *     desarrollo le llegaría igual a un cliente real con un link roto; el agente, en
     *     cambio, solo contesta conversaciones que entraron por un número de Kapso real con
     *     firma HMAC válida — si el bot está respondiendo, el entorno es productivo de hecho;
     * (b) con la condición puesta, esta funcionalidad sería imposible de probar fuera de
     *     producción, que es justo el agujero que vino a tapar la simulación de entrantes;
     * (c) el gate real y suficiente es que el negocio tenga tienda cargada (`users.online`).
     *
     * 🔴 OJO CON LAS DOS COLUMNAS QUE SE LLAMAN `online`, QUE NO SON LO MISMO:
     * `users.online` es un string con la URL de la tienda del negocio; `articles.online` es un
     * booleano (0/1, default 1) que dice si ESE artículo está publicado en la tienda. Hacen
     * falta las dos: sin la primera no hay dominio al que apuntar, y sin la segunda se arma un
     * link a un 404, porque el ecommerce lista con `->where('online', 1)` y un artículo
     * despublicado no tiene página. Un negocio puede tener miles de artículos en el ERP y solo
     * unos cientos publicados, así que el caso es la norma y no el borde.
     *
     * El artículo despublicado SÍ sigue apareciendo en el catálogo que ve la IA, con su precio
     * y su stock: se vende en el mostrador aunque no esté en la web, y contestar eso está bien.
     * Lo único que se le saca es el link.
     *
     * @param array|object          $article
     * @param \App\Models\User|null $owner_user
     *
     * @return string Vacío si no se puede armar el link (sin dueño, sin tienda, sin slug, o
     *                artículo no publicado en la tienda).
     */
    private function article_url($article, $owner_user): string
    {
        if (is_null($owner_user)) {
            return '';
        }

        // `articles.online`: si el artículo no está publicado, su URL pública da 404. Se corta
        // acá, antes de armar nada. El default de la columna es 1, así que un artículo viejo
        // sin el dato explícito se sigue tratando como publicado, igual que en la tienda.
        $article_online = $this->article_field($article, 'online', 1);
        if (! $article_online) {
            return '';
        }

        $online = trim((string) ($owner_user->online ?? ''));
        $slug   = trim((string) $this->article_field($article, 'slug', ''));

        if ($online === '' || $slug === '') {
            return '';
        }

        return $online.'/articulos/'.$slug.'/'.$owner_user->id;
    }

    /**
     * Lee un campo de un artículo del RAG sin importar si llegó como array o como objeto
     * (stdClass). `search_similar_articles` devuelve stdClass; se mantiene el soporte a
     * array por compatibilidad con el código anterior a este refactor.
     *
     * @param array|object $article
     * @param string       $key
     * @param mixed        $default
     *
     * @return mixed
     */
    private function article_field($article, string $key, $default)
    {
        if (is_array($article)) {
            return array_key_exists($key, $article) ? $article[$key] : $default;
        }

        if (is_object($article)) {
            return isset($article->$key) ? $article->$key : $default;
        }

        return $default;
    }

    /**
     * Arma el texto plano "Cliente: ..." / "Negocio: ..." por renglón, usado como
     * transcripción de la conversación para el endpoint de resumen.
     *
     * @param \Illuminate\Support\Collection<int, WhatsappChatMessage> $history_messages
     *
     * @return string
     */
    private function build_transcript($history_messages): string
    {
        $lines = [];

        foreach ($history_messages as $message) {
            $body = trim((string) $message->body);
            if ($body === '') {
                continue;
            }

            $speaker = $message->direction === 'in' ? 'Cliente' : 'Negocio';
            $lines[] = $speaker.': '.$body;
        }

        return implode("\n", $lines);
    }

    /**
     * Extrae el texto de la respuesta de Anthropic (concatena todos los bloques `text`
     * del array `content`), igual que el comportamiento previo a este refactor.
     *
     * @param array|null $body Body decodificado de la respuesta HTTP.
     *
     * @return string
     */
    private function extract_text($body): string
    {
        $text = '';

        if (is_array($body) && isset($body['content']) && is_array($body['content'])) {
            foreach ($body['content'] as $block) {
                if (is_array($block) && ($block['type'] ?? '') === 'text' && isset($block['text'])) {
                    $text .= (string) $block['text'];
                }
            }
        }

        return trim($text);
    }

    /**
     * Cliente HTTP hacia Anthropic con configuración TLS desde config/services.php.
     */
    private function build_http_client(): PendingRequest
    {
        $api_key = (string) config('services.anthropic.api_key');

        $http = Http::withHeaders([
            'x-api-key'         => $api_key,
            'anthropic-version' => '2023-06-01',
            'content-type'      => 'application/json',
        ])->timeout(60);

        $verify_ssl = (bool) config('services.anthropic.verify_ssl', true);
        $ca_bundle  = config('services.anthropic.ca_bundle');

        if (! $verify_ssl) {
            $http = $http->withoutVerifying();
        } elseif (is_string($ca_bundle) && $ca_bundle !== '' && is_file($ca_bundle)) {
            $http = $http->withOptions(['verify' => $ca_bundle]);
        }

        return $http;
    }
}
