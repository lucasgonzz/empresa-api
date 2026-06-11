<?php

namespace App\Services;

use App\Models\WhatsappBotConfig;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsappBotAiService
{
    /**
     * Genera una respuesta de lenguaje natural a la consulta del cliente usando el catálogo
     * de artículos de la empresa (búsqueda por embeddings) y Claude (Anthropic).
     *
     * @param string            $query   Pregunta del cliente.
     * @param int               $user_id ID del usuario (empresa) dueño del catálogo.
     * @param WhatsappBotConfig $config  Configuración activa del bot.
     *
     * @return string Respuesta generada, o cadena vacía si hubo un error.
     */
    public function generate_response(string $query, int $user_id, WhatsappBotConfig $config): string
    {
        try {
            $api_key = (string) config('services.anthropic.api_key');
            if ($api_key === '') {
                Log::channel('daily')->warning('WhatsappBotAiService: ANTHROPIC_API_KEY no configurada.');
                return '';
            }

            $embedding_service = new ArticleEmbeddingService();
            $articles = $embedding_service->search_similar_articles($query, $user_id, 8);

            $system_prompt = $this->build_system_prompt();
            $user_content  = $this->build_user_content($query, $articles);

            $model = (string) config('services.anthropic.model', 'claude-sonnet-4-20250514');
            $http  = $this->build_http_client();

            $response = $http->post('https://api.anthropic.com/v1/messages', [
                'model'      => $model,
                'max_tokens' => 500,
                'system'     => $system_prompt,
                'messages'   => [
                    ['role' => 'user', 'content' => $user_content],
                ],
            ]);

            if ($response->failed()) {
                Log::channel('daily')->error('WhatsappBotAiService: error HTTP de Anthropic.', [
                    'status' => $response->status(),
                    'body'   => substr($response->body(), 0, 500),
                ]);
                return '';
            }

            $body = $response->json();
            $text = '';
            if (isset($body['content']) && is_array($body['content'])) {
                foreach ($body['content'] as $block) {
                    if (is_array($block) && ($block['type'] ?? '') === 'text' && isset($block['text'])) {
                        $text .= (string) $block['text'];
                    }
                }
            }

            return trim($text);
        } catch (\Throwable $exception) {
            Log::channel('daily')->error('WhatsappBotAiService: excepción al generar respuesta.', [
                'user_id' => $user_id,
                'error'   => $exception->getMessage(),
            ]);
            return '';
        }
    }

    /**
     * System prompt del asistente de ventas por WhatsApp.
     */
    private function build_system_prompt(): string
    {
        return <<<SYSTEM
Sos el asistente de ventas de este negocio. Respondés consultas de clientes sobre el catálogo de productos.
Respondé en español rioplatense, de forma natural y directa, como un vendedor real respondería por WhatsApp.
Sin markdown, sin listas con guiones, sin asteriscos. Texto plano.
Si el producto no está en el catálogo, decilo claramente.
Si el cliente pregunta el precio, siempre incluirlo en la respuesta.
SYSTEM;
    }

    /**
     * Construye el mensaje de usuario con los artículos relevantes y la pregunta del cliente.
     *
     * @param string $query    Consulta original del cliente.
     * @param array  $articles Artículos retornados por search_similar_articles.
     *
     * @return string
     */
    private function build_user_content(string $query, array $articles): string
    {
        if (empty($articles)) {
            $catalog_section = 'No se encontraron productos relevantes en el catálogo.';
        } else {
            $lines = [];
            foreach ($articles as $article) {
                $name  = (string) ($article['name'] ?? $article->name ?? '');
                $price = $article['price'] ?? $article->price ?? null;
                $line  = '- ' . $name;
                if ($price !== null && $price !== '') {
                    $line .= ' | Precio: $' . number_format((float) $price, 2, ',', '.');
                }
                $lines[] = $line;
            }
            $catalog_section = implode("\n", $lines);
        }

        return <<<USER
Productos del catálogo más relevantes para la consulta:
{$catalog_section}

Pregunta del cliente: {$query}
USER;
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
