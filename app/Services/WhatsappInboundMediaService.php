<?php

namespace App\Services;

use App\Models\WhatsappBotConfig;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Descarga el archivo de un mensaje entrante de WhatsApp (imagen o nota de voz) y guarda una
 * copia local privada (misión whatsapp-sidebar-multimedia). Port recortado de
 * `admin-api/app/Services/WhatsappInboundMediaService.php`, que ya resolvió esto entero.
 *
 * 🔴 ESTE SERVICIO NUNCA LANZA. Todos sus caminos públicos devuelven `null` ante cualquier
 * problema y lo dejan escrito en el log. Corre adentro del request del webhook de Kapso: una
 * excepción acá no sería "no se pudo bajar la foto", sería el mensaje entero perdido y el
 * cliente esperando una respuesta que nunca va a llegar. El que llama tiene que poder
 * ignorar el `null` y seguir guardando la fila.
 *
 * 🔴 QUE LA DESCARGA FALLE NO CANCELA EL MENSAJE. La fila se guarda igual, con
 * `media_type` cargado y `media_path` en null — y sobre todo, `last_inbound_at` (la ventana
 * de 24 h de Meta) queda escrito antes de que este servicio salga a la red. Ese es el bug de
 * fondo que arregla esta misión: hasta ahora una imagen se descartaba en silencio y ni
 * siquiera abría la ventana, así que el operador no podía contestarle al cliente que le
 * acababa de mandar una foto. Ver `WhatsappChatHelper::store_inbound_message()`.
 *
 * 🔴 LA URL REMOTA DE KAPSO NO SE PERSISTE EN NINGUNA COLUMNA, a propósito: es una URL
 * firmada que expira en horas. El diseño es "bajala y olvidate"; la copia local no expira.
 *
 * Lo que NO se porta del molde y no es un olvido:
 *  - La conversión con ffmpeg: existe en `admin-api` porque su SPA grababa con `MediaRecorder`
 *    (webm en Chrome, fMP4 en Safari) y había que convertir el contenedor. Acá se graba con
 *    `opus-recorder`, que ya produce ogg/opus válido. Además la detección usa `which ffmpeg`,
 *    que es POSIX y en WAMP nunca encuentra nada.
 *  - `resolve_whatsapp_audio_mime()`, por la misma razón.
 *  - El `resolve_extension()` basado en el nombre de archivo del webhook (ver la lista blanca).
 */
class WhatsappInboundMediaService
{
    /**
     * Tipos entrantes con adjunto que este servicio sabe resolver (D15).
     *
     * `document`, `video` y `sticker` quedan afuera a propósito: cada tipo nuevo necesita una
     * forma de dibujarse en la burbuja de la conversación, y el alcance de la misión son
     * audios e imágenes. Un tipo que no esté acá sigue devolviendo `null` y el mensaje se
     * ignora, exactamente como antes.
     *
     * @var array<int, string>
     */
    private const SUPPORTED_TYPES = ['image', 'audio', 'ptt', 'voice'];

    /**
     * 🔴 LA ÚNICA FUENTE DE VERDAD DE LA EXTENSIÓN Y DEL `Content-Type` (D16).
     *
     * El molde de `admin-api` deduce la extensión con prioridad `filename > mime > fallback`,
     * o sea que el nombre de archivo que viene en el webhook decide cómo se llama el archivo
     * en disco, sin sanitizar. Allá no explota porque los adjuntos se sirven por una ruta
     * temporal firmada; acá los serviría PHP con el `Content-Type` que le pasemos, así que un
     * `documento.php` del payload sería una forma de escribir un archivo con esa extensión
     * dentro del servidor. Con la lista blanca la extensión es puramente cosmética: sale del
     * mime real y no hay ningún string del atacante en el nombre del archivo.
     *
     * Un mime que no esté en esta lista NO se guarda (la fila del mensaje sí se guarda igual).
     *
     * @var array<string, string>
     */
    private const MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/jpg'  => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
        'audio/ogg'  => 'ogg',
        'audio/opus' => 'ogg',
        'audio/mpeg' => 'mp3',
        'audio/mp4'  => 'm4a',
        'audio/aac'  => 'm4a',
        'audio/amr'  => 'amr',
        'audio/webm' => 'webm',
    ];

    /**
     * Tope del binario descargado, en bytes (D28).
     *
     * Los límites reales de la Cloud API de Meta son 16 MB para audio y 5 MB para imagen, así
     * que 20 MB deja margen para cualquier variante y sigue siendo un techo. Arriba de esto el
     * archivo se descarta y la fila se guarda sin adjunto: la alternativa (guardarlo igual)
     * es que un solo mensaje llene el disco del servidor.
     *
     * @var int
     */
    private const MAX_DOWNLOAD_BYTES = 20971520;

    /**
     * Segundos de timeout de cada request de descarga.
     *
     * Es un literal y no `config('services.client_api.timeout', 15)` como el molde: esa clave
     * NO EXISTE en el `config/services.php` de este repo, así que el `config()` devolvería
     * siempre el default y sería una constante disfrazada de configuración — el mismo "código
     * muerto" que el relevamiento le marca al molde, donde el default escrito (30) nunca se
     * usa porque la clave existe y vale 15. El valor efectivo de allá es 15 y se replica a
     * conciencia, no por copia.
     *
     * @var int
     */
    private const DOWNLOAD_TIMEOUT_SECONDS = 15;

    /**
     * Saca del nodo `message` del webhook todo lo que hace falta para bajar el adjunto.
     *
     * 🔴 DEVUELVE EL DESCRIPTOR AUNQUE NO HAYA ENCONTRADO NI URL NI ID. Es deliberado: el
     * `media_type` tiene que llegar a la fila igual para que la conversación muestre "acá
     * llegó una imagen" incluso cuando el archivo no se pudo bajar (D14). Devuelve `null`
     * solo cuando el tipo no lleva adjunto (texto) o no está soportado.
     *
     * @param  array  $message  Nodo `message` del payload del webhook.
     * @param  string  $type  Tipo crudo del mensaje (`image`, `audio`, `ptt`, `voice`).
     *
     * @return array|null  ['media_type', 'media_url', 'media_id', 'mime', 'caption'] o null.
     */
    public function extract_inbound_media(array $message, $type): ?array
    {
        $type = strtolower((string) $type);

        if (! in_array($type, self::SUPPORTED_TYPES, true)) {
            return null;
        }

        $payload_key = $this->resolve_media_payload_key($message, $type);
        $kapso = (isset($message['kapso']) && is_array($message['kapso'])) ? $message['kapso'] : [];

        $media_url = $this->resolve_media_url($message, $payload_key, $kapso);

        // El id de Meta: el tercer camino de descarga (por el proxy de Kapso) es lo único que
        // queda cuando el payload no trae URL, así que se extrae siempre.
        $media_id = null;
        if (isset($message[$payload_key]['id'])) {
            $media_id = trim((string) $message[$payload_key]['id']);
            if ($media_id === '') {
                $media_id = null;
            }
        }

        $mime = '';
        if (isset($message[$payload_key]['mime_type'])) {
            $mime = trim((string) $message[$payload_key]['mime_type']);
        }
        // Fallback al `Type:` del texto legado de `kapso.content`
        // ("[Image attached (foo.jpg)] [Size: … | Type: image/jpeg] URL: https://…").
        if ($mime === '' && isset($kapso['content'])) {
            $parsed_meta = $this->parse_kapso_content_metadata((string) $kapso['content']);
            $mime = (string) $parsed_meta['mime'];
        }

        $caption = '';
        if (isset($message[$payload_key]['caption'])) {
            $caption = trim((string) $message[$payload_key]['caption']);
        }

        // 🔴 LOG OBLIGATORIO (riesgo R3 del plan). No existe documentación oficial del payload
        // de medios de Kapso en ningún lado: las cuatro rutas de `resolve_media_url()` salen de
        // leer el código de `admin-api`, y no hay garantía de que sean las que llegan acá. Si
        // ninguna matcheó, este log es lo ÚNICO que le va a decir a Lucas dónde está el campo
        // cuando llegue el primer mensaje real con foto. No sacarlo "porque hace ruido": hace
        // ruido exactamente cuando algo no anda.
        if ($media_url === null || $media_url === '') {
            Log::channel('daily')->warning('WhatsappInboundMediaService: no se encontró la URL del adjunto en el payload.', [
                'tipo'           => $type,
                'nodo'           => $payload_key,
                'claves_message' => array_keys($message),
                'claves_nodo'    => array_keys((isset($message[$payload_key]) && is_array($message[$payload_key])) ? $message[$payload_key] : []),
                'claves_kapso'   => array_keys($kapso),
                'hay_media_id'   => ! is_null($media_id),
            ]);
        }

        return [
            'media_type' => $type === 'image' ? 'image' : 'audio',
            'media_url'  => ($media_url !== null && $media_url !== '') ? $media_url : null,
            'media_id'   => $media_id,
            'mime'       => $mime !== '' ? $mime : null,
            'caption'    => $caption,
        ];
    }

    /**
     * Baja el binario y lo deja en el disco `local`, devolviendo las tres columnas de medio
     * para el `create()` del mensaje.
     *
     * 🔴 EL ARCHIVO VA AL DISCO `local`, NUNCA AL `public`. `routes/web.php:199-207` sirve
     * `/storage/{path}` de forma pública, sin auth y con `->where('path', '.*')`: una foto o
     * un audio de una conversación privada quedaría abierto para siempre a cualquiera que
     * tenga o adivine la URL. Se sirve por la ruta autenticada
     * `GET api/whatsapp-chats/{chat}/media/{message}`. Si alguien "simplifica" esto mandando
     * los archivos a `Storage::disk('public')`, lo que se rompe es la privacidad de todas las
     * conversaciones, en silencio y sin ningún error.
     *
     * @param  array  $media  Descriptor devuelto por `extract_inbound_media()`.
     * @param  int  $chat_id  Chat al que pertenece el mensaje (arma la carpeta).
     * @param  string|null  $wa_message_id  Id del mensaje en WhatsApp. 🔴 SE USA SOLO PARA EL
     *                                      NOMBRE DETERMINISTA DEL ARCHIVO Y NO SE PERSISTE
     *                                      (D17): `handle_delivery_status_event()` busca por
     *                                      la columna `wa_message_id` SIN filtrar `direction`,
     *                                      así que sembrarla con ids de entrantes le abre una
     *                                      colisión que esta misión no tiene por qué tocar.
     * @param  string  $body_seed  Cuerpo del mensaje; solo para la semilla de respaldo del
     *                             nombre cuando el payload no trae `message.id`.
     * @param  WhatsappBotConfig  $config  Config activa del bot (api key y phone_number_id).
     *
     * @return array|null  ['media_path', 'media_mime', 'media_size'], o null si no hubo
     *                     archivo. Nunca lanza.
     */
    public function store_inbound_media(array $media, $chat_id, $wa_message_id, $body_seed, WhatsappBotConfig $config): ?array
    {
        try {
            $api_key = trim((string) $config->kapso_api_key);
            $phone_number_id = trim((string) $config->phone_number_id);
            $media_url = isset($media['media_url']) ? (string) $media['media_url'] : '';
            $media_id = isset($media['media_id']) ? (string) $media['media_id'] : '';

            $binary = null;

            if ($media_url !== '') {
                $binary = $this->download_media_binary($media_url, $api_key);
            }

            // Tercer camino: pedirle el archivo al proxy de Meta por el id del medio. Es lo
            // único que queda cuando el payload no trajo URL o la URL ya venció.
            if (($binary === null || $binary === '') && $media_id !== '' && $phone_number_id !== '') {
                $binary = $this->download_media_binary_by_whatsapp_id($media_id, $phone_number_id, $api_key);
            }

            if ($binary === null || $binary === '') {
                Log::channel('daily')->warning('WhatsappInboundMediaService: no se pudo descargar el adjunto.', [
                    'chat_id'  => $chat_id,
                    'url'      => $media_url !== '' ? $media_url : null,
                    'media_id' => $media_id !== '' ? $media_id : null,
                ]);

                return null;
            }

            $size = strlen($binary);
            if ($size > self::MAX_DOWNLOAD_BYTES) {
                Log::channel('daily')->warning('WhatsappInboundMediaService: adjunto descartado por tamaño.', [
                    'chat_id'    => $chat_id,
                    'size_bytes' => $size,
                    'tope_bytes' => self::MAX_DOWNLOAD_BYTES,
                ]);

                return null;
            }

            $mime = self::safe_mime(isset($media['mime']) ? $media['mime'] : null);
            if ($mime === null) {
                // Sin mime confiable no se guarda el archivo: la extensión y el `Content-Type`
                // con el que después lo sirve PHP salen SOLO de la lista blanca (D16), nunca
                // del nombre que mandó el webhook. La fila del mensaje sí se guarda.
                Log::channel('daily')->warning('WhatsappInboundMediaService: mime fuera de la lista blanca, no se guarda el archivo.', [
                    'chat_id' => $chat_id,
                    'mime'    => isset($media['mime']) ? $media['mime'] : null,
                ]);

                return null;
            }

            $stored_path = 'whatsapp/' . $chat_id . '/' . $this->build_stored_name($mime, $wa_message_id, $chat_id, $body_seed);

            Storage::disk('local')->put($stored_path, $binary);

            Log::channel('daily')->info('WhatsappInboundMediaService: adjunto entrante guardado.', [
                'chat_id'    => $chat_id,
                'path'       => $stored_path,
                'mime'       => $mime,
                'size_bytes' => $size,
            ]);

            return [
                'media_path' => $stored_path,
                'media_mime' => $mime,
                'media_size' => $size,
            ];
        } catch (\Throwable $exception) {
            // Ver el docblock de la clase: acá adentro corre el webhook de Kapso. Un throw
            // sería el mensaje entero perdido por no haber podido guardar una foto.
            Log::channel('daily')->error('WhatsappInboundMediaService: excepción al persistir el adjunto.', [
                'chat_id' => $chat_id,
                'error'   => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Normaliza un mime y lo devuelve solo si está en la lista blanca; si no, `null`.
     *
     * Es pública y estática porque la usan los dos extremos del archivo: este servicio al
     * guardarlo, y `WhatsappChatController@media` al servirlo. Tiene que ser LA MISMA lista en
     * los dos lados (D16): si al servir se confiara en la columna sin revisar, una fila vieja
     * o cargada por otro camino podría hacer que PHP devuelva un `text/html` con el cuerpo que
     * eligió un tercero.
     *
     * @param  string|null  $mime  Mime crudo, tal como vino.
     * @return string|null  El mime normalizado, o null si no está permitido.
     */
    public static function safe_mime($mime): ?string
    {
        $mime = strtolower(trim((string) $mime));
        if ($mime === '') {
            return null;
        }

        // El navegador entrega los blobs grabados como "audio/ogg; codecs=opus" y algunos CDN
        // devuelven el charset pegado al mime. Se corta en el `;` antes de comparar, igual que
        // hace `WhatsappBotSendService::send_audio()`: con un `===` sobre el string crudo, que
        // es la forma obvia de escribirlo, un ogg perfectamente válido caería de la lista.
        $punto_y_coma = strpos($mime, ';');
        if ($punto_y_coma !== false) {
            $mime = trim(substr($mime, 0, $punto_y_coma));
        }

        if (! isset(self::MIME_EXTENSIONS[$mime])) {
            return null;
        }

        return $mime;
    }

    /**
     * Extensión que le corresponde a un mime ya validado.
     *
     * 🔴 VIVE AL LADO DE `safe_mime()` A PROPÓSITO, Y NO SE COPIA A NINGÚN OTRO ARCHIVO. Las dos
     * respuestas salen de la MISMA constante, así que no pueden desincronizarse: si mañana se
     * suma un mime a la lista blanca, la extensión aparece sola en los tres lugares que la usan
     * (guardar el entrante, guardar el saliente y armar el nombre del multipart).
     *
     * Nació duplicada en `WhatsappChatController` el 15/8/2026, porque quien construyó el envío
     * del operador tenía prohibido tocar este archivo y necesitaba la traducción. Se unificó en
     * cuanto se detectó: dos tablas iguales en dos archivos es una divergencia con fecha de
     * vencimiento, y la mitad que se olvide de actualizar acepta un mime que la otra no sabe
     * nombrar — o peor, lo guarda con una extensión que Meta después rechaza.
     *
     * @param  string|null  $mime  Mime crudo o ya normalizado.
     * @return string|null  La extensión sin punto, o null si el mime no está permitido.
     */
    public static function extension_for($mime): ?string
    {
        $normalizado = self::safe_mime($mime);
        if ($normalizado === null) {
            return null;
        }

        return self::MIME_EXTENSIONS[$normalizado];
    }

    /**
     * Nombre del archivo en disco, determinista (D17).
     *
     * 🔴 EL NOMBRE SALE DEL ID DEL MENSAJE, NO DEL RELOJ NI DE UN RANDOM. Kapso reintenta el
     * webhook cuando no le llega el 200 a tiempo, y un nombre con `time()` dejaría dos, tres o
     * cinco copias del mismo audio en disco. Con el md5 del id, el reintento sobreescribe el
     * mismo archivo: es la idempotencia de facto del adjunto.
     *
     * La extensión sale del mime ya validado contra la lista blanca; el nombre no contiene ni
     * un byte que haya elegido quien mandó el mensaje.
     *
     * @param  string  $mime  Mime ya normalizado y validado.
     * @param  string|null  $wa_message_id  Id del mensaje en WhatsApp, si vino.
     * @param  int  $chat_id  Solo para la semilla de respaldo.
     * @param  string  $body_seed  Solo para la semilla de respaldo.
     * @return string
     */
    private function build_stored_name($mime, $wa_message_id, $chat_id, $body_seed): string
    {
        $seed = trim((string) $wa_message_id);

        if ($seed === '') {
            // Sin id no hay idempotencia perfecta posible, pero esta semilla al menos hace que
            // el MISMO mensaje reintentado caiga en el mismo archivo.
            $seed = $chat_id . '|' . (string) $body_seed . '|' . $mime;
        }

        return 'wa_' . substr(md5($seed), 0, 12) . '.' . self::MIME_EXTENSIONS[$mime];
    }

    /**
     * Clave del nodo del payload donde vive el adjunto.
     *
     * Comentario textual del molde: *"Notas de voz: Kapso/Meta pueden usar type ptt con nodo
     * audio o ptt."* O sea que el `type` del mensaje y el nombre del nodo pueden no coincidir.
     * `voice` NUNCA es clave de nodo; `image` usa su propio nombre sin aliasing.
     *
     * @param  array  $message
     * @param  string  $type
     * @return string
     */
    private function resolve_media_payload_key(array $message, $type): string
    {
        if (isset($message[$type]) && is_array($message[$type])) {
            return $type;
        }

        if (in_array($type, ['ptt', 'voice', 'audio'], true)) {
            if (isset($message['audio']) && is_array($message['audio'])) {
                return 'audio';
            }
            if (isset($message['ptt']) && is_array($message['ptt'])) {
                return 'ptt';
            }
        }

        return $type;
    }

    /**
     * Resuelve la URL del archivo en Kapso / Meta probando cuatro rutas, en orden, y quedándose
     * con la primera no vacía.
     *
     * 🔴 ESTAS CUATRO RUTAS SON LA ÚNICA DOCUMENTACIÓN QUE EXISTE DEL PAYLOAD DE MEDIOS DE
     * KAPSO. No hay doc oficial en ningún repo: salen de leer `admin-api`, que es la única
     * implementación que funciona en producción. No sacar ninguna "porque no se usa" — cada
     * una está por un payload real distinto:
     *
     *  1. `message.kapso.media_url`  — snake_case, la que Kapso usa realmente hoy.
     *  2. `message.kapso.mediaUrl`   — camelCase, variante defensiva.
     *  3. `message.{nodo}.link`      — el campo estándar de Meta.
     *  4. el regex sobre `message.kapso.content` — formato legado, donde el adjunto viajaba
     *     como texto: "[Image attached (foo.jpg)] [Size: … | Type: image/jpeg] URL: https://…".
     *
     * ⚠️ Ninguna mira `message.image.url`, `message.media.url` ni el nivel raíz del payload.
     * Si el primer mensaje real no matchea, el log de `extract_inbound_media()` dice qué claves
     * llegaron de verdad y ahí se agrega la quinta ruta.
     *
     * @param  array  $message
     * @param  string  $payload_key
     * @param  array  $kapso
     * @return string|null
     */
    private function resolve_media_url(array $message, $payload_key, array $kapso): ?string
    {
        if (isset($kapso['media_url']) && is_string($kapso['media_url'])) {
            $url = trim($kapso['media_url']);
            if ($url !== '') {
                return $url;
            }
        }

        if (isset($kapso['mediaUrl']) && is_string($kapso['mediaUrl'])) {
            $url = trim($kapso['mediaUrl']);
            if ($url !== '') {
                return $url;
            }
        }

        if (isset($message[$payload_key]['link']) && is_string($message[$payload_key]['link'])) {
            $url = trim($message[$payload_key]['link']);
            if ($url !== '') {
                return $url;
            }
        }

        if (isset($kapso['content'])) {
            $parsed_meta = $this->parse_kapso_content_metadata((string) $kapso['content']);

            return $parsed_meta['url'];
        }

        return null;
    }

    /**
     * Parsea el texto legado de `kapso.content`, donde el adjunto viajaba embebido en el
     * cuerpo del mensaje en vez de en un nodo propio.
     *
     * @param  string  $content
     * @return array{url: string|null, mime: string|null}
     */
    private function parse_kapso_content_metadata($content): array
    {
        $url = null;
        $mime = null;

        if (preg_match('/URL:\s*(https?:\/\/\S+)/i', $content, $matches)) {
            $url = rtrim($matches[1], '.,;)"\'');
        } elseif (preg_match('#(https?://[^\s\]\)\"\'<>]+)#i', $content, $matches)) {
            $url = rtrim($matches[1], '.,;)"\'');
        }

        if (preg_match('/Type:\s*([^\|\]]+)/i', $content, $matches)) {
            $mime = trim($matches[1]);
        }

        return [
            'url'  => $url,
            'mime' => $mime,
        ];
    }

    /**
     * Descarga los bytes del archivo remoto. Dos intentos, y los dos hacen falta.
     *
     * 1. Con `X-API-Key`, pisando el `Accept` para que acepte cualquier tipo:
     *    `KapsoHttpClient::make()` deja `Accept: application/json` cuando hay api key, y con
     *    ese header un CDN que devuelve un binario puede contestar 406.
     *
     * 2. 🔴 REINTENTO SIN API KEY, Y ESTO NO SE PUEDE SACAR. Comentario textual del molde:
     *    *"Reintento sin API key por si la URL ya es firmada y pública."* Kapso entrega los
     *    adjuntos por URLs S3 presignadas: la firma se calcula sobre un conjunto de headers, y
     *    mandar headers de más la rompe — el servidor devuelve 403 sobre una URL que es
     *    perfectamente válida. Sin este segundo intento las URLs presignadas NO BAJAN NUNCA, y
     *    el síntoma es "las fotos llegan sin archivo" sin ningún error visible. Es lo primero
     *    que alguien va a querer borrar por parecer un reintento redundante.
     *
     * Sin `->retry()` (a diferencia de los envíos): esto corre adentro del webhook y cada
     * reintento son 15 segundos más que Kapso pasa esperando el 200.
     *
     * @param  string  $url
     * @param  string  $api_key
     * @return string|null
     */
    private function download_media_binary($url, $api_key): ?string
    {
        try {
            $http = KapsoHttpClient::make($api_key !== '' ? $api_key : null, self::DOWNLOAD_TIMEOUT_SECONDS);
            $response = $http->withHeaders([
                'Accept' => '*/*',
            ])->get($url);

            if ($response->successful()) {
                return $response->body();
            }

            // Reintento sin API key por si la URL ya es firmada y pública (ver el docblock).
            $fallback = KapsoHttpClient::make(null, self::DOWNLOAD_TIMEOUT_SECONDS);
            $response = $fallback->get($url);
            if ($response->successful()) {
                return $response->body();
            }

            Log::channel('daily')->warning('WhatsappInboundMediaService: la descarga del adjunto no fue exitosa.', [
                'url'    => $url,
                'status' => $response->status(),
            ]);
        } catch (\Throwable $exception) {
            Log::channel('daily')->error('WhatsappInboundMediaService: excepción al descargar el adjunto.', [
                'url'   => $url,
                'error' => $exception->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Tercer camino: resolver el archivo por el id de Meta, contra el proxy de Kapso.
     *
     * El endpoint contesta de dos formas distintas y hay que aceptar las dos: un JSON con
     * `{url: …}` (y entonces hay que bajar esa URL), o el binario directo. La heurística para
     * distinguirlos es el largo del cuerpo: arriba de 100 bytes es el archivo, porque un JSON
     * de error de Meta siempre es más corto que eso. No es elegante, pero es lo que hay: el
     * proxy no manda un `Content-Type` confiable.
     *
     * @param  string  $media_id  Id del medio en Meta (`message.{nodo}.id`).
     * @param  string  $phone_number_id  El de la config activa del bot.
     * @param  string  $api_key
     * @return string|null
     */
    private function download_media_binary_by_whatsapp_id($media_id, $phone_number_id, $api_key): ?string
    {
        $metadata_endpoint = 'https://api.kapso.ai/meta/whatsapp/v24.0/'
            . rawurlencode((string) $media_id)
            . '?phone_number_id=' . rawurlencode((string) $phone_number_id);

        try {
            $http = KapsoHttpClient::make($api_key !== '' ? $api_key : null, self::DOWNLOAD_TIMEOUT_SECONDS);
            $response = $http->get($metadata_endpoint);

            if (! $response->successful()) {
                return null;
            }

            $payload = $response->json();
            if (is_array($payload) && isset($payload['url']) && is_string($payload['url'])) {
                return $this->download_media_binary($payload['url'], $api_key);
            }

            $body = $response->body();
            if ($body !== null && $body !== '' && strlen($body) > 100) {
                return $body;
            }
        } catch (\Throwable $exception) {
            Log::channel('daily')->error('WhatsappInboundMediaService: excepción al resolver el adjunto por id.', [
                'media_id' => $media_id,
                'error'    => $exception->getMessage(),
            ]);
        }

        return null;
    }
}
