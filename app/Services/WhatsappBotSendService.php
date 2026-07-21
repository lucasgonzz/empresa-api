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
     * @param  string             $to        Número destino (puede incluir prefijo de país).
     * @param  WhatsappTemplate   $template  Plantilla ya validada (owner, `status = aprobada`, cantidad de variables).
     * @param  array              $variables Valores de las variables del body, en el mismo orden que `$template->variables`.
     * @param  WhatsappBotConfig  $config    Configuración activa del bot para esta empresa.
     *
     * @return string|null  El `wa_message_id` que devuelve Kapso/Meta si el envío fue
     *                       exitoso, o null si no se pudo enviar o Kapso no lo informó.
     */
    public function send_template(string $to, WhatsappTemplate $template, array $variables, WhatsappBotConfig $config): ?string
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

        // Payload base de la plantilla; solo agregamos el componente body si hay variables que inyectar.
        $payload = [
            'messaging_product' => 'whatsapp',
            'to'                => $to_digits,
            'type'              => 'template',
            'template'          => [
                'name'     => $template->meta_template_name,
                'language' => ['code' => $template->language],
            ],
        ];

        if (! empty($variables)) {
            $payload['template']['components'] = [[
                'type'       => 'body',
                'parameters' => array_map(function ($value) {
                    return ['type' => 'text', 'text' => (string) $value];
                }, $variables),
            ]];
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
