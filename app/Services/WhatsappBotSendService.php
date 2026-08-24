<?php

namespace App\Services;

use App\Http\Controllers\Helpers\WhatsappPhoneHelper;
use App\Models\WhatsappBotConfig;
use App\Models\WhatsappChat;
use App\Models\WhatsappTemplate;
use Illuminate\Support\Facades\Log;

class WhatsappBotSendService
{
    /**
     * Envía un mensaje de texto al número destino usando la configuración del bot.
     *
     * @param string            $to     Número destino (puede incluir prefijo de país).
     * @param string            $body   Texto del mensaje.
     * @param WhatsappBotConfig $config Configuración activa del bot para esta empresa.
     *
     * @return string|null  El `wa_message_id` que devuelve Kapso/Meta si el envío fue
     *                       exitoso (para poder matchear los webhooks de estado de entrega
     *                       del Prompt 02), o null si no se pudo enviar o Kapso no lo informó.
     */
    public function send_text(string $to, string $body, WhatsappBotConfig $config): ?string
    {
        // Freno de la simulación. Ver `chat_en_simulacion()`: si el último entrante de este
        // chat lo inyectó `simulate-inbound`, la ventana de 24 h está forzada y el texto libre
        // NO puede salir. Se devuelve null, que es el mismo "no se pudo enviar" que ya manejan
        // todos los callers.
        if ($this->chat_en_simulacion($to, $config)) {
            return null;
        }

        $to_digits = preg_replace('/\D+/', '', $to) ?? '';
        if ($to_digits === '') {
            Log::channel('daily')->warning('WhatsappBotSendService: número destino inválido.', [
                'to' => $to,
            ]);
            return null;
        }

        $text_body = trim($body);
        if ($text_body === '') {
            Log::channel('daily')->warning('WhatsappBotSendService: cuerpo de mensaje vacío.');
            return null;
        }

        $endpoint = 'https://api.kapso.ai/meta/whatsapp/v24.0/'
            . rawurlencode((string) $config->phone_number_id)
            . '/messages';

        try {
            $http = KapsoHttpClient::make((string) $config->kapso_api_key);

            $response = $http->post($endpoint, [
                'messaging_product' => 'whatsapp',
                'to'                => $to_digits,
                'type'              => 'text',
                'text'              => [
                    'body' => $text_body,
                ],
            ]);

            if ($response->successful()) {
                Log::channel('daily')->info('WhatsappBotSendService: mensaje enviado con éxito.', [
                    'to'     => $to_digits,
                    'status' => $response->status(),
                ]);

                return self::extract_wa_message_id($response->json());
            } else {
                Log::channel('daily')->error('WhatsappBotSendService: error HTTP de Kapso.', [
                    'to'     => $to_digits,
                    'status' => $response->status(),
                    'body'   => substr($response->body(), 0, 500),
                ]);
                return null;
            }
        } catch (\Throwable $exception) {
            Log::channel('daily')->error('WhatsappBotSendService: excepción al enviar mensaje.', [
                'to'    => $to_digits,
                'error' => $exception->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Envía una plantilla de Meta aprobada (grupo 137, Prompt 04). Es el único camino
     * válido para iniciar/retomar una conversación una vez cerrada la ventana de 24 h
     * de servicio (`WhatsappChat::is_within_service_window()` en false), pero también
     * funciona con la ventana abierta. Arma el payload `type: template` con un
     * componente `body` de parámetros de texto en el mismo orden que `$variables`
     * (mismo shape que usa `admin-api` para los seguimientos `cc_seg_*`).
     *
     * Soporta opcionalmente un header tipo DOCUMENT (grupo 137, Prompt 05: plantilla
     * `cc_cli_comprobante`, usada por `SaleWhatsappSenderService` para mandar el PDF de
     * la venta cuando la ventana de 24 h está cerrada): si se pasan `$header_document_url`
     * y `$header_document_filename`, se agrega un componente `header` con
     * `parameters[0].document.link/filename` antes del componente `body`.
     *
     * @param  string             $to        Número destino (puede incluir prefijo de país).
     * @param  WhatsappTemplate   $template  Plantilla ya validada (owner, `status = aprobada`, cantidad de variables).
     * @param  array              $variables Valores de las variables del body, en el mismo orden que `$template->variables`.
     * @param  WhatsappBotConfig  $config    Configuración activa del bot para esta empresa.
     * @param  string|null        $header_document_url       URL pública del documento del header (null si la plantilla no tiene header DOCUMENT).
     * @param  string|null        $header_document_filename  Nombre de archivo a mostrar para el documento del header.
     *
     * @return string|null  El `wa_message_id` que devuelve Kapso/Meta si el envío fue
     *                       exitoso, o null si no se pudo enviar o Kapso no lo informó.
     */
    public function send_template(string $to, WhatsappTemplate $template, array $variables, WhatsappBotConfig $config, ?string $header_document_url = null, ?string $header_document_filename = null): ?string
    {
        $to_digits = preg_replace('/\D+/', '', $to) ?? '';
        if ($to_digits === '') {
            Log::channel('daily')->warning('WhatsappBotSendService: número destino inválido (template).', [
                'to' => $to,
            ]);
            return null;
        }

        $endpoint = 'https://api.kapso.ai/meta/whatsapp/v24.0/'
            . rawurlencode((string) $config->phone_number_id)
            . '/messages';

        // Payload base de la plantilla; solo agregamos components si hay header document y/o variables de body.
        $payload = [
            'messaging_product' => 'whatsapp',
            'to'                => $to_digits,
            'type'              => 'template',
            'template'          => [
                'name'     => $template->meta_template_name,
                'language' => ['code' => $template->language],
            ],
        ];

        // Los components van en el orden que Meta espera dentro del array: header primero, body después.
        $components = [];

        if (! is_null($header_document_url) && trim($header_document_url) !== '') {
            $header_document = ['link' => $header_document_url];
            if (! is_null($header_document_filename) && trim($header_document_filename) !== '') {
                $header_document['filename'] = trim($header_document_filename);
            }
            $components[] = [
                'type'       => 'header',
                'parameters' => [[
                    'type'     => 'document',
                    'document' => $header_document,
                ]],
            ];
        }

        if (! empty($variables)) {
            $components[] = [
                'type'       => 'body',
                'parameters' => array_map(function ($value) {
                    return ['type' => 'text', 'text' => (string) $value];
                }, $variables),
            ];
        }

        if (! empty($components)) {
            $payload['template']['components'] = $components;
        }

        try {
            $http = KapsoHttpClient::make((string) $config->kapso_api_key);

            $response = $http->post($endpoint, $payload);

            if ($response->successful()) {
                Log::channel('daily')->info('WhatsappBotSendService: plantilla enviada con éxito.', [
                    'to'       => $to_digits,
                    'template' => $template->meta_template_name,
                    'status'   => $response->status(),
                ]);

                return self::extract_wa_message_id($response->json());
            } else {
                Log::channel('daily')->error('WhatsappBotSendService: error HTTP de Kapso al enviar plantilla.', [
                    'to'       => $to_digits,
                    'template' => $template->meta_template_name,
                    'status'   => $response->status(),
                    'body'     => substr($response->body(), 0, 500),
                ]);
                return null;
            }
        } catch (\Throwable $exception) {
            Log::channel('daily')->error('WhatsappBotSendService: excepción al enviar plantilla.', [
                'to'       => $to_digits,
                'template' => $template->meta_template_name,
                'error'    => $exception->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Envía un documento (grupo 137, Prompt 05: comprobante de venta) referenciado por URL
     * pública. A diferencia de `admin-api` (que sube el archivo con `upload_media` y manda
     * `document.id`), acá el PDF ya se sirve por una ruta pública propia
     * (`sale/pdf/{id}`, sin auth), así que se manda directo como `document.link`: no hace
     * falta el paso extra de subida a Kapso. Solo funciona dentro de la ventana de 24 h de
     * servicio; fuera de ventana hay que usar `send_template` con header DOCUMENT.
     *
     * @param  string             $to             Número destino (puede incluir prefijo de país).
     * @param  string             $document_url   URL pública del documento a enviar.
     * @param  string             $filename       Nombre de archivo a mostrar en WhatsApp.
     * @param  string             $caption        Texto corto que acompaña al documento.
     * @param  WhatsappBotConfig  $config         Configuración activa del bot para esta empresa.
     *
     * @return string|null  El `wa_message_id` que devuelve Kapso/Meta si el envío fue
     *                       exitoso, o null si no se pudo enviar o Kapso no lo informó.
     */
    public function send_document(string $to, string $document_url, string $filename, string $caption, WhatsappBotConfig $config): ?string
    {
        // 🔴 ACÁ NO VA EL FRENO DE SIMULACIÓN. Estuvo y se sacó el 15/8/2026: el criterio
        // "frenar todo lo que solo es legal dentro de la ventana de 24 h" es correcto para el
        // texto libre, pero este método no es solo del módulo de WhatsApp. Su único caller es
        // `SaleWhatsappSenderService`, o sea el comprobante de una venta, que lo dispara una
        // VENTA REAL y no la simulación. Con el freno acá, si el dueño simulaba un mensaje
        // sobre el teléfono de un cliente real (el modal de simulación tiene un buscador de
        // clientes que autocompleta justamente eso), el chat quedaba marcado hasta que ese
        // cliente escribiera de verdad, y mientras tanto CADA venta a ese cliente generaba un
        // comprobante que nunca salía. El vendedor no veía nada: la pantalla de ventas no sabe
        // nada de la simulación. El razonamiento completo está en `chat_en_simulacion()`.
        $to_digits = preg_replace('/\D+/', '', $to) ?? '';
        if ($to_digits === '') {
            Log::channel('daily')->warning('WhatsappBotSendService: número destino inválido (documento).', [
                'to' => $to,
            ]);
            return null;
        }

        $document_url = trim($document_url);
        if ($document_url === '') {
            Log::channel('daily')->warning('WhatsappBotSendService: URL de documento vacía.');
            return null;
        }

        $endpoint = 'https://api.kapso.ai/meta/whatsapp/v24.0/'
            . rawurlencode((string) $config->phone_number_id)
            . '/messages';

        $document_payload = ['link' => $document_url];
        if (trim($filename) !== '') {
            $document_payload['filename'] = trim($filename);
        }
        if (trim($caption) !== '') {
            $document_payload['caption'] = trim($caption);
        }

        try {
            $http = KapsoHttpClient::make((string) $config->kapso_api_key);

            $response = $http->post($endpoint, [
                'messaging_product' => 'whatsapp',
                'to'                => $to_digits,
                'type'              => 'document',
                'document'          => $document_payload,
            ]);

            if ($response->successful()) {
                Log::channel('daily')->info('WhatsappBotSendService: documento enviado con éxito.', [
                    'to'     => $to_digits,
                    'status' => $response->status(),
                ]);

                return self::extract_wa_message_id($response->json());
            } else {
                Log::channel('daily')->error('WhatsappBotSendService: error HTTP de Kapso al enviar documento.', [
                    'to'     => $to_digits,
                    'status' => $response->status(),
                    'body'   => substr($response->body(), 0, 500),
                ]);
                return null;
            }
        } catch (\Throwable $exception) {
            Log::channel('daily')->error('WhatsappBotSendService: excepción al enviar documento.', [
                'to'    => $to_digits,
                'error' => $exception->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Indica si el chat de este teléfono está en modo simulación, o sea si su último mensaje
     * entrante lo inyectó `WhatsappBotController@simulate_inbound` en vez de haberlo escrito
     * una persona (columna `whatsapp_chats.last_inbound_simulated`).
     *
     * 🔴 ESTO NO ES UNA LIMITACIÓN QUE HAYA QUE "ARREGLAR". Es el freno que existe para que
     * la simulación no mande WhatsApp de verdad, y sacarlo reabre un agujero grave. El
     * razonamiento completo, porque leyendo el `return null` suelto parece una función a
     * medio hacer:
     *
     * La simulación tiene que recorrer TODO el flujo interno (persistencia, ventana,
     * agrupación con su demora, generación de la respuesta, estado de confirmación, registro
     * de tokens) — si no, no prueba nada. Eso incluye pasar por `store_inbound_message()`, que
     * setea `last_inbound_at = now()`. Y `last_inbound_at` es la ÚNICA fuente de verdad de la
     * ventana de 24 h de Meta (`WhatsappChat::is_within_service_window()`). O sea que
     * simulando un mensaje desde un número que nunca escribió, el sistema queda convencido de
     * que hay una conversación abierta con ese número. Sin este freno, el agente generaba la
     * respuesta y se la mandaba DE VERDAD por Kapso a alguien que nunca habló con el negocio.
     *
     * Meta rechaza esos mensajes de su lado, así que "no llegan" — pero cada intento le baja
     * la calificación al número de WhatsApp Business del negocio, y un número con mala
     * calificación queda limitado o dado de baja. Es el activo que ComercioCity le gestiona al
     * cliente: no se arriesga para que una prueba se vea más linda.
     *
     * Qué SÍ pasa con la respuesta simulada: se persiste igual, marcada con
     * `whatsapp_chat_messages.is_simulated = 1`, y se ve en la conversación. Lucas puede leer
     * exactamente lo que el agente contestó sin que se le haya mandado nada a nadie, que es
     * justo para lo que se construyó la simulación.
     *
     * Por qué el freno vive acá y no en el job del agente: hay dos caminos de envío (el
     * inmediato con `ai_confirm_delay_seconds = 0` y el diferido que espera la confirmación
     * humana), y los dos terminan en `AutoSendWhatsappAiMessageJob` llamando a `send_text()`.
     * Este método es el único punto por el que pasan los dos.
     *
     * 🔴 SOLO LO LLAMA `send_text()`, y eso es a propósito (corregido el 15/8/2026, después de
     * que el revisor lo marcara como bloqueante). El criterio no es "frenar todo envío de un
     * chat marcado" sino "frenar lo que se apoya en la ventana de 24 h falseada Y lo dispara
     * la simulación". Los otros dos métodos quedan afuera por motivos distintos:
     *
     *   - `send_template()`: una plantilla aprobada de Meta se puede mandar con la ventana
     *     abierta o cerrada, así que no depende del dato forzado. No hay nada que proteger.
     *   - `send_document()`: su único caller es `SaleWhatsappSenderService`, o sea el
     *     comprobante de una venta. Ese envío lo dispara una venta real, no la simulación, y
     *     frenarlo hacía que el comprobante se perdiera en silencio en cada venta a un cliente
     *     cuyo chat quedó marcado por una prueba. Un freno que solo el módulo de WhatsApp
     *     conoce no puede colgarse del camino de otro módulo que no lo mira.
     *
     * Lo que queda como riesgo asumido: si el chat está en simulación, la ventana forzada hace
     * que el comprobante salga por `send_document()` (documento suelto) cuando en realidad no
     * hay conversación abierta, y Meta lo puede rechazar. Eso ya NO es silencioso:
     * `SaleWhatsappSenderService` chequea el null y lanza `SaleWhatsappSendException`, así que
     * queda un error en el log y un 422 en el camino manual, en vez de un "enviado" mentiroso.
     *
     * Cómo se sale del modo simulación: solo. La columna se reescribe en CADA mensaje
     * entrante, en los dos caminos (webhook real y simulación), así que apenas el cliente
     * escribe de verdad queda en 0 y el chat vuelve a enviar normalmente. No hay estado
     * pegado ni proceso de limpieza.
     *
     * @param  string             $to        Número destino, en cualquier formato.
     * @param  WhatsappBotConfig  $config    Config del bot; su `user_id` acota la búsqueda del chat.
     *
     * @return bool True si hay que frenar el envío.
     */
    private function chat_en_simulacion(string $to, WhatsappBotConfig $config): bool
    {
        // Los chats se persisten siempre con el teléfono normalizado (solo dígitos); acá se
        // normaliza de nuevo porque el caller puede pasar tanto `$chat->phone` (ya normalizado)
        // como un número crudo.
        $phone = WhatsappPhoneHelper::normalize($to);
        if ($phone === '') {
            return false;
        }

        $chat = WhatsappChat::where('user_id', $config->user_id)
            ->where('phone', $phone)
            ->first();

        // Sin chat no hay marca que mirar: no es una simulación, se envía normalmente.
        if (is_null($chat) || ! $chat->last_inbound_simulated) {
            return false;
        }

        Log::channel('daily')->warning('WhatsappBotSendService: envío de texto frenado, el chat está en modo simulación.', [
            'chat_id' => $chat->id,
            'phone'   => $phone,
            'camino'  => 'send_text',
            'motivo'  => 'El último mensaje entrante fue simulado, no lo escribió el cliente. No se sale a Kapso.',
        ]);

        return true;
    }

    /**
     * Extrae el id del mensaje (`wa_message_id`) de la respuesta de Kapso/Meta. El formato
     * estándar de la Cloud API es `{ "messages": [{ "id": "wamid.XXXX" }] }`, pero se
     * revisan rutas alternativas de forma defensiva por si Kapso lo envuelve distinto.
     *
     * @param  mixed  $response_body  Cuerpo decodificado (array) de la respuesta de Kapso.
     * @return string|null
     */
    private static function extract_wa_message_id($response_body): ?string
    {
        if (! is_array($response_body)) {
            return null;
        }

        $candidates = [
            isset($response_body['messages'][0]['id']) ? $response_body['messages'][0]['id'] : null,
            isset($response_body['message']['id']) ? $response_body['message']['id'] : null,
            isset($response_body['id']) ? $response_body['id'] : null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }

    /**
     * Sube un archivo al endpoint de media de Kapso/Meta y devuelve el `media_id` con el que
     * después se lo puede enviar (misión whatsapp-sidebar-multimedia).
     *
     * 🔴 POR QUÉ SE SUBE EL ARCHIVO Y NO SE MANDA POR `link`, COMO HACE `send_document()`:
     * el PDF del comprobante se sirve por una ruta pública propia (`sale/pdf/{id}`, sin auth),
     * así que Meta lo baja solo. Los audios y las fotos de una conversación NO: viven en el
     * disco `local`, fuera del docroot, justamente para que una conversación privada no quede
     * accesible por URL a cualquiera que la adivine. No hay URL que Meta pueda bajar, así que
     * el archivo tiene que viajar en el cuerpo de la request. Y en local no habría forma ni
     * queriendo: `ApiUrlHelper::base()` devuelve `http://empresa.local:8000` y Kapso no llega.
     * (La única excepción es la foto del catálogo que manda el agente, que ya es pública a
     * propósito y va por `link` a través de `send_image()`.)
     *
     * 🔴 EL TERCER PARÁMETRO DE `KapsoHttpClient::make()` VA EN `false`, Y NO ES DECORATIVO.
     * En `true` (el default, y lo que hacen las otras tres llamadas de esta clase) el cliente
     * manda `Content-Type: application/json`; con ese header ya puesto Guzzle no puede escribir
     * el suyo con el boundary del multipart y la subida falla entera. Es el único uso de ese
     * parámetro en todo el repo, así que si alguien "empareja" esta llamada con las otras tres,
     * lo que se rompe es solo esto y el síntoma aparece lejos de la causa.
     *
     * 🔴 `$filename` TIENE QUE TENER UNA EXTENSIÓN COHERENTE CON `$mime`: Meta valida esa
     * coherencia y rechaza la subida si no coinciden. Acá NO se corrige ni se reescribe a
     * propósito: si el caller manda un `.jpg` con mime `audio/ogg`, eso es un bug del caller y
     * tiene que doler ahí, no quedar tapado por una normalización silenciosa que después
     * nadie encuentra.
     *
     * ⚠️ NO LLEVA EL FRENO DE SIMULACIÓN, y no es un olvido: no recibe el teléfono, así que no
     * puede saber de qué chat se trata. El caller tiene que frenar ANTES de subir. Si no, un
     * chat en simulación igual sube el archivo a Kapso de verdad (aunque después
     * `send_image()`/`send_audio()` no lo manden), que es exactamente el viaje que la
     * simulación no tiene que hacer.
     *
     * ⚠️ `file_get_contents()` carga el archivo entero en memoria. Con los topes de la Cloud
     * API de Meta (16 MB el audio, 5 MB la imagen) es aceptable; si algún día se suben
     * documentos de 100 MB hay que pasar a un stream.
     *
     * @param  string             $absolute_path  Ruta absoluta del archivo en disco.
     * @param  string             $mime           Mime real del archivo; es el que Meta asocia al media_id.
     * @param  string             $filename       Nombre en el multipart, con extensión coherente con $mime.
     * @param  WhatsappBotConfig  $config         Configuración activa del bot para esta empresa.
     *
     * @return string|null  El `media_id` de Meta si la subida salió bien, o null si falló.
     *                       Nunca lanza: loguea y devuelve null, igual que el resto de la clase.
     */
    public function upload_media(string $absolute_path, string $mime, string $filename, WhatsappBotConfig $config): ?string
    {
        if (! is_file($absolute_path)) {
            Log::channel('daily')->warning('WhatsappBotSendService: el archivo a subir no existe.', [
                'path' => $absolute_path,
            ]);
            return null;
        }

        $endpoint = 'https://api.kapso.ai/meta/whatsapp/v24.0/'
            . rawurlencode((string) $config->phone_number_id)
            . '/media';

        try {
            $file_contents = file_get_contents($absolute_path);
            if ($file_contents === false || $file_contents === '') {
                Log::channel('daily')->warning('WhatsappBotSendService: archivo a subir vacío o ilegible.', [
                    'path' => $absolute_path,
                ]);
                return null;
            }

            $multipart_name = trim($filename);
            if ($multipart_name === '') {
                $multipart_name = basename($absolute_path);
            }

            // El timeout es más largo que el default de 15 s de KapsoHttpClient a propósito:
            // acá viaja el archivo entero por la conexión de salida del servidor, y un audio de
            // 16 MB (el tope de Meta) no entra en 15 s en una subida lenta. Un corte del cliente
            // HTTP se vería igual que un rechazo de Meta, y el operador leería "no se pudo
            // enviar" sobre un archivo perfectamente válido.
            $http = KapsoHttpClient::make((string) $config->kapso_api_key, 60, false);

            $response = $http
                ->attach('file', $file_contents, $multipart_name, ['Content-Type' => $mime])
                ->post($endpoint, [
                    'messaging_product' => 'whatsapp',
                ]);

            if ($response->successful()) {
                $payload = $response->json();

                if (is_array($payload) && isset($payload['id']) && trim((string) $payload['id']) !== '') {
                    Log::channel('daily')->info('WhatsappBotSendService: archivo subido con éxito.', [
                        'mime'   => $mime,
                        'bytes'  => strlen($file_contents),
                        'status' => $response->status(),
                    ]);

                    return trim((string) $payload['id']);
                }

                // 200 sin id. Se trata como error y no como éxito silencioso: sin `media_id` no
                // hay nada que enviar después, y devolver algo distinto de null haría que el
                // caller diera el envío por bueno. Es el mismo modo de falla que ya costó un
                // hallazgo en la misión A ("un envío fallido se marcaba como enviado").
                Log::channel('daily')->error('WhatsappBotSendService: Kapso aceptó la subida pero no devolvió media_id.', [
                    'mime'   => $mime,
                    'status' => $response->status(),
                    'body'   => substr($response->body(), 0, 500),
                ]);

                return null;
            }

            Log::channel('daily')->error('WhatsappBotSendService: error HTTP de Kapso al subir el archivo.', [
                'mime'   => $mime,
                'status' => $response->status(),
                'body'   => substr($response->body(), 0, 500),
            ]);

            return null;
        } catch (\Throwable $exception) {
            Log::channel('daily')->error('WhatsappBotSendService: excepción al subir el archivo.', [
                'path'  => $absolute_path,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Envía una imagen, referenciada por `media_id` (archivo ya subido con `upload_media()`)
     * o por `link` (URL pública) (misión whatsapp-sidebar-multimedia).
     *
     * Los dos caminos existen porque hay dos orígenes con propiedades distintas:
     *
     *  - `['id' => …]` para lo que manda el operador desde el composer. Ese archivo es privado,
     *    vive en el disco `local` y no hay URL que Meta pueda bajar: hay que subirlo primero.
     *  - `['link' => …]` para la foto del catálogo que manda el agente. `images.hosting_url` YA
     *    es una URL absoluta y pública (la misma que ve cualquiera en la tienda), así que
     *    bajarla y volver a subirla sería pagar dos veces por un archivo que es público a
     *    propósito, y sumar dos viajes a Kapso adentro del embudo serializado del agente.
     *
     * 🔴 SIN `filename` EN EL PAYLOAD. Meta lo acepta en `document` pero NO en `image`, y
     * mandarlo hace que rechace el mensaje entero. Es la única diferencia con `send_document()`,
     * que por lo demás tiene el mismo shape y es de donde salió este método: copiarlo entero
     * sin sacar esa línea es el error natural.
     *
     * 🔴 LLEVA EL FRENO DE SIMULACIÓN, igual que `send_text()`. Una imagen suelta (o sea, no
     * plantilla) solo es legal dentro de la ventana de 24 h de servicio, y la simulación falsea
     * esa ventana: `store_inbound_message()` escribe `last_inbound_at` también para el mensaje
     * inyectado, así que el sistema queda convencido de que hay conversación abierta con un
     * número que nunca escribió. Sin el freno, mandarle una foto a ese número sale de verdad
     * por Kapso, Meta lo rechaza de su lado y cada intento le baja la calificación al número de
     * WhatsApp Business del negocio. El razonamiento completo está en `chat_en_simulacion()`.
     *
     * 🔴 CALLERS, Y POR QUÉ ESTO IMPORTA MÁS QUE EL RESTO DEL DOCBLOCK. Al escribirse este
     * método no tiene ninguno; los dos que va a tener son del módulo de WhatsApp y de ningún
     * otro lado: el endpoint de envío de medios del operador (`WhatsappChatController`) y la
     * foto del producto que recomienda el agente (`WhatsappChatHelper::send_pending_ai_message()`).
     * Si alguna vez un módulo fuera de WhatsApp llama a este método, hay que revisar este guard
     * — es exactamente lo que pasó con `send_document()` el 15/8/2026: un freno pensado para el
     * agente se filtró al PDF del comprobante de venta, y cada venta a un cliente cuyo chat
     * había quedado marcado por una prueba perdía su comprobante en silencio, con el log
     * diciendo que se había enviado. Cómo se chequea, parado en la raíz de `empresa-api`:
     *
     *     git grep -n "send_image\|send_audio\|upload_media" -- app/ | grep -v "WhatsappBotSendService.php"
     *
     * @param  string             $to       Número destino (puede incluir prefijo de país).
     * @param  array              $media    ['id' => '<media_id>'] o ['link' => '<url pública>'].
     * @param  string             $caption  Texto que acompaña la imagen. Vacío = sin epígrafe.
     * @param  WhatsappBotConfig  $config   Configuración activa del bot para esta empresa.
     *
     * @return string|null  El `wa_message_id` que devuelve Kapso/Meta si el envío fue exitoso,
     *                       o null si el chat está en simulación, si no se pudo enviar, o si
     *                       Kapso no lo informó.
     */
    public function send_image(string $to, array $media, string $caption, WhatsappBotConfig $config): ?string
    {
        // Mismo freno y en el mismo lugar que en `send_text()`: lo primero de todo, para que un
        // chat en simulación no gaste ni una llamada.
        if ($this->chat_en_simulacion($to, $config)) {
            return null;
        }

        $to_digits = preg_replace('/\D+/', '', $to) ?? '';
        if ($to_digits === '') {
            Log::channel('daily')->warning('WhatsappBotSendService: número destino inválido (imagen).', [
                'to' => $to,
            ]);
            return null;
        }

        // `id` y `link` son excluyentes para Meta: mandando los dos rechaza el mensaje. Si
        // vinieran ambos gana `id`, porque es el archivo propio y ya está subido (o sea, ya se
        // pagó el viaje). No se lanza excepción por recibir los dos: el caller no tiene forma
        // de recuperarse de eso y frenar un envío por una discusión de forma sería peor.
        $image_payload = [];
        if (isset($media['id']) && trim((string) $media['id']) !== '') {
            $image_payload['id'] = trim((string) $media['id']);
        } elseif (isset($media['link']) && trim((string) $media['link']) !== '') {
            $image_payload['link'] = trim((string) $media['link']);
        }

        if (empty($image_payload)) {
            Log::channel('daily')->warning('WhatsappBotSendService: imagen sin media_id ni link.', [
                'to' => $to_digits,
            ]);
            return null;
        }

        if (trim($caption) !== '') {
            $image_payload['caption'] = trim($caption);
        }

        $endpoint = 'https://api.kapso.ai/meta/whatsapp/v24.0/'
            . rawurlencode((string) $config->phone_number_id)
            . '/messages';

        try {
            $http = KapsoHttpClient::make((string) $config->kapso_api_key);

            $response = $http->post($endpoint, [
                'messaging_product' => 'whatsapp',
                'to'                => $to_digits,
                'type'              => 'image',
                'image'             => $image_payload,
            ]);

            if ($response->successful()) {
                Log::channel('daily')->info('WhatsappBotSendService: imagen enviada con éxito.', [
                    'to'     => $to_digits,
                    'via'    => isset($image_payload['id']) ? 'media_id' : 'link',
                    'status' => $response->status(),
                ]);

                return self::extract_wa_message_id($response->json());
            } else {
                Log::channel('daily')->error('WhatsappBotSendService: error HTTP de Kapso al enviar imagen.', [
                    'to'     => $to_digits,
                    'status' => $response->status(),
                    'body'   => substr($response->body(), 0, 500),
                ]);
                return null;
            }
        } catch (\Throwable $exception) {
            Log::channel('daily')->error('WhatsappBotSendService: excepción al enviar imagen.', [
                'to'    => $to_digits,
                'error' => $exception->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Envía un audio ya subido con `upload_media()` (misión whatsapp-sidebar-multimedia).
     *
     * A diferencia de `send_image()` acá no hay camino por `link`: los audios de una
     * conversación son siempre archivos privados del disco `local`, nunca URLs públicas.
     *
     * 🔴 `voice: true` ES LO QUE HACE QUE LLEGUE COMO NOTA DE VOZ y no como un archivo adjunto
     * con ícono de clip. Sin esa bandera el audio llega igual, así que la falla no se ve en
     * ningún log ni en ningún test que mire el status: solo se nota mirando cómo le apareció al
     * cliente. Meta la acepta únicamente para ogg/opus; con cualquier otro contenedor (mp3,
     * m4a, amr) mandarla hace que rechace el mensaje. Por eso va atada al mime y no siempre.
     *
     * El mime se normaliza antes de comparar (minúsculas y recortando lo que va después del
     * `;`) porque el navegador entrega los blobs grabados como `audio/ogg; codecs=opus`. Con un
     * `===` sobre el string crudo —que es la forma obvia de escribirlo— cada nota de voz que
     * mande el operador desde el composer saldría como archivo adjunto, sin un solo error en
     * ningún lado.
     *
     * 🔴 LLEVA EL FRENO DE SIMULACIÓN, igual que `send_text()`, y por el mismo motivo: un audio
     * suelto solo es legal dentro de la ventana de 24 h, y la simulación falsea esa ventana
     * escribiendo `last_inbound_at` desde un número que nunca escribió. Ver `chat_en_simulacion()`.
     *
     * 🔴 CALLERS. Al escribirse este método no tiene ninguno; el único que va a tener es el
     * endpoint de envío de medios del operador (`WhatsappChatController`), del módulo de
     * WhatsApp. Si alguna vez un módulo fuera de WhatsApp llama a este método, hay que revisar
     * este guard — es exactamente lo que pasó con `send_document()` el 15/8/2026, cuando un
     * freno pensado para el agente se filtró al PDF del comprobante de venta. Cómo se chequea,
     * parado en la raíz de `empresa-api`:
     *
     *     git grep -n "send_image\|send_audio\|upload_media" -- app/ | grep -v "WhatsappBotSendService.php"
     *
     * @param  string             $to        Número destino (puede incluir prefijo de país).
     * @param  string             $media_id  Id que devolvió `upload_media()`.
     * @param  string             $mime      Mime del archivo subido; decide si va como nota de voz.
     * @param  WhatsappBotConfig  $config    Configuración activa del bot para esta empresa.
     *
     * @return string|null  El `wa_message_id` que devuelve Kapso/Meta si el envío fue exitoso,
     *                       o null si el chat está en simulación, si no se pudo enviar, o si
     *                       Kapso no lo informó.
     */
    public function send_audio(string $to, string $media_id, string $mime, WhatsappBotConfig $config): ?string
    {
        // Mismo freno y en el mismo lugar que en `send_text()`: lo primero de todo.
        if ($this->chat_en_simulacion($to, $config)) {
            return null;
        }

        $to_digits = preg_replace('/\D+/', '', $to) ?? '';
        if ($to_digits === '') {
            Log::channel('daily')->warning('WhatsappBotSendService: número destino inválido (audio).', [
                'to' => $to,
            ]);
            return null;
        }

        $media_id_limpio = trim($media_id);
        if ($media_id_limpio === '') {
            Log::channel('daily')->warning('WhatsappBotSendService: audio sin media_id.', [
                'to' => $to_digits,
            ]);
            return null;
        }

        $audio_payload = ['id' => $media_id_limpio];

        // `audio/ogg; codecs=opus` y `AUDIO/OGG` son el mismo contenedor: se recorta el
        // parámetro y se baja a minúsculas antes de comparar. Se usa strpos y no str_contains
        // porque el repo corre en PHP 7.4.
        $mime_base = strtolower(trim($mime));
        $corte = strpos($mime_base, ';');
        if ($corte !== false) {
            $mime_base = trim(substr($mime_base, 0, $corte));
        }

        if ($mime_base === 'audio/ogg') {
            $audio_payload['voice'] = true;
        }

        $endpoint = 'https://api.kapso.ai/meta/whatsapp/v24.0/'
            . rawurlencode((string) $config->phone_number_id)
            . '/messages';

        try {
            $http = KapsoHttpClient::make((string) $config->kapso_api_key);

            $response = $http->post($endpoint, [
                'messaging_product' => 'whatsapp',
                'to'                => $to_digits,
                'type'              => 'audio',
                'audio'             => $audio_payload,
            ]);

            if ($response->successful()) {
                Log::channel('daily')->info('WhatsappBotSendService: audio enviado con éxito.', [
                    'to'     => $to_digits,
                    'voice'  => isset($audio_payload['voice']),
                    'status' => $response->status(),
                ]);

                return self::extract_wa_message_id($response->json());
            } else {
                Log::channel('daily')->error('WhatsappBotSendService: error HTTP de Kapso al enviar audio.', [
                    'to'     => $to_digits,
                    'status' => $response->status(),
                    'body'   => substr($response->body(), 0, 500),
                ]);
                return null;
            }
        } catch (\Throwable $exception) {
            Log::channel('daily')->error('WhatsappBotSendService: excepción al enviar audio.', [
                'to'    => $to_digits,
                'error' => $exception->getMessage(),
            ]);
            return null;
        }
    }
}
