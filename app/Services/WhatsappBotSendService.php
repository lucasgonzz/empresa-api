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
        if ($this->chat_en_simulacion($to, $config, 'texto')) {
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
        // Mismo freno que en `send_text()`, y por el mismo motivo: el documento por link solo
        // es válido DENTRO de la ventana de 24 h, y en un chat en simulación esa ventana está
        // forzada. Ver `chat_en_simulacion()`.
        if ($this->chat_en_simulacion($to, $config, 'documento')) {
            return null;
        }

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
     * Este método es el único punto por el que pasan los dos, y además atrapa cualquier
     * camino futuro que quiera mandar texto o documento a un chat en simulación.
     *
     * Por qué las plantillas (`send_template()`) NO se frenan: el criterio es "frenar todo lo
     * que solo es legal DENTRO de la ventana de 24 h", que es exactamente lo que la simulación
     * falsea. Una plantilla aprobada de Meta se puede mandar con la ventana abierta o cerrada,
     * así que no depende del dato forzado y no hay nada que proteger ahí.
     *
     * Cómo se sale del modo simulación: solo. La columna se reescribe en CADA mensaje
     * entrante, en los dos caminos (webhook real y simulación), así que apenas el cliente
     * escribe de verdad queda en 0 y el chat vuelve a enviar normalmente. No hay estado
     * pegado ni proceso de limpieza.
     *
     * @param  string             $to        Número destino, en cualquier formato.
     * @param  WhatsappBotConfig  $config    Config del bot; su `user_id` acota la búsqueda del chat.
     * @param  string             $tipo_envio Para el log: 'texto' | 'documento'.
     *
     * @return bool True si hay que frenar el envío.
     */
    private function chat_en_simulacion(string $to, WhatsappBotConfig $config, string $tipo_envio): bool
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

        Log::channel('daily')->warning('WhatsappBotSendService: envío frenado, el chat está en modo simulación.', [
            'chat_id'    => $chat->id,
            'phone'      => $phone,
            'tipo_envio' => $tipo_envio,
            'motivo'     => 'El último mensaje entrante fue simulado, no lo escribió el cliente. No se sale a Kapso.',
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
}
