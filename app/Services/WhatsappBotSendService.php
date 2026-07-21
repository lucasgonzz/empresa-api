<?php

namespace App\Services;

use App\Models\WhatsappBotConfig;
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
