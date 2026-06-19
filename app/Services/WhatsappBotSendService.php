<?php

namespace App\Services;

use App\Models\WhatsappBotConfig;
use Illuminate\Support\Facades\Log;

class WhatsappBotSendService
{
    /**
     * Envía un mensaje de texto al número destino usando la configuración del bot.
     *
     * @param string            $to     Número destino (puede incluir prefijo de país).
     * @param string            $body   Texto del mensaje.
     * @param WhatsappBotConfig $config Configuración activa del bot para esta empresa.
     */
    public function send_text(string $to, string $body, WhatsappBotConfig $config): void
    {
        $to_digits = preg_replace('/\D+/', '', $to) ?? '';
        if ($to_digits === '') {
            Log::channel('daily')->warning('WhatsappBotSendService: número destino inválido.', [
                'to' => $to,
            ]);
            return;
        }

        $text_body = trim($body);
        if ($text_body === '') {
            Log::channel('daily')->warning('WhatsappBotSendService: cuerpo de mensaje vacío.');
            return;
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
            } else {
                Log::channel('daily')->error('WhatsappBotSendService: error HTTP de Kapso.', [
                    'to'     => $to_digits,
                    'status' => $response->status(),
                    'body'   => substr($response->body(), 0, 500),
                ]);
            }
        } catch (\Throwable $exception) {
            Log::channel('daily')->error('WhatsappBotSendService: excepción al enviar mensaje.', [
                'to'    => $to_digits,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
