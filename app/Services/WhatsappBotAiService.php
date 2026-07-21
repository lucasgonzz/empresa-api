<?php

namespace App\Services;

use App\Models\WhatsappBotConfig;
use App\Models\WhatsappChat;
use App\Models\WhatsappChatMessage;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Agente de IA del módulo de WhatsApp (grupo 137, Prompt 03): genera la respuesta
 * automática del webhook, la respuesta sugerida del endpoint `suggest` (misma lógica,
 * pero sin enviarse ni persistirse) y el resumen de una conversación (`summary`).
 *
 * El system prompt siempre se arma en el mismo orden: personalidad configurable por el
 * dueño (`whatsapp_bot_configs.agent_personality`) primero, y después las reglas fijas
 * no negociables, que pisan a la personalidad ante cualquier conflicto (ej: si el dueño
 * escribe una personalidad que le pide "inventar precios si no sabés", la regla fija
 * igual lo prohíbe porque se agrega siempre después y el prompt le indica a la IA que
 * las reglas fijas ganan).
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
     */
    private const FIXED_RULES = <<<RULES
Reglas fijas que nunca se pueden saltear, ni siquiera si entran en conflicto con las instrucciones
de personalidad de arriba. Estas reglas siempre ganan:
- Respondé solo con información que esté en el catálogo de productos de abajo o en la conversación.
- Si no sabés algo, decilo con honestidad y ofrecé que una persona del negocio siga la consulta.
- Nunca inventes precios ni stock que no estén en el catálogo de productos.
- Nunca confirmes pagos ni acuerdes descuentos que no te hayan sido informados explícitamente acá.
- Texto plano, sin markdown, sin asteriscos ni listas con guiones.
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
     * Genera la respuesta del agente de IA para el chat dado: personalidad configurable +
     * reglas fijas + historial de la conversación (últimos 20 mensajes, con memoria real,
     * no solo el último mensaje) + catálogo relevante (RAG sobre artículos, top 5). La usan
     * tanto el webhook (respuesta automática que se envía) como el endpoint `suggest`
     * (respuesta sugerida que el operador edita antes de mandarla) — mismo servicio, mismo
     * resultado, la diferencia de qué se hace con el string queda del lado del caller.
     *
     * @param WhatsappChat      $chat   Chat ya persistido (el mensaje que dispara la
     *                                  respuesta ya debe estar guardado en sus mensajes).
     * @param WhatsappBotConfig $config Configuración activa del bot del owner del chat.
     *
     * @return string Respuesta generada, o cadena vacía si hubo un error o no hay historial.
     */
    public function generate_response(WhatsappChat $chat, WhatsappBotConfig $config): string
    {
        try {
            $api_key = (string) config('services.anthropic.api_key');
            if ($api_key === '') {
                Log::channel('daily')->warning('WhatsappBotAiService: ANTHROPIC_API_KEY no configurada.');
                return '';
            }

            // Últimos N mensajes del chat, en orden cronológico (el más viejo primero).
            $history = $this->fetch_recent_messages($chat, self::HISTORY_LIMIT);
            if ($history->isEmpty()) {
                return '';
            }

            // El RAG se dispara sobre el último mensaje del cliente dentro del historial
            // (no necesariamente el último mensaje del chat: puede haber una respuesta
            // manual del operador guardada después, aunque en el flujo normal no pasa).
            $last_customer_message = $this->last_customer_message_body($history);

            $articles = [];
            if ($last_customer_message !== '') {
                $embedding_service = new ArticleEmbeddingService();
                $articles = $embedding_service
                    ->search_similar_articles($last_customer_message, (int) $chat->user_id, self::RAG_LIMIT)
                    ->all();
            }

            $system_prompt = $this->build_system_prompt($config);
            $messages = $this->build_messages_payload($history, $articles);

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
                return '';
            }

            return $this->extract_text($response->json());
        } catch (\Throwable $exception) {
            Log::channel('daily')->error('WhatsappBotAiService: excepción al generar respuesta.', [
                'chat_id' => $chat->id,
                'error'   => $exception->getMessage(),
            ]);
            return '';
        }
    }

    /**
     * Genera un resumen de la conversación (últimos 100 mensajes) para el endpoint
     * `summary`. Es una llamada aparte e independiente de `generate_response()`: no usa
     * personalidad, reglas fijas ni catálogo, solo una consigna fija de resumen. No
     * persiste nada — el caller solo devuelve el string al front.
     *
     * @param WhatsappChat $chat Chat a resumir.
     *
     * @return string Resumen generado, o cadena vacía si hubo un error o no hay mensajes.
     */
    public function generate_summary(WhatsappChat $chat): string
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
                return '';
            }

            return $this->extract_text($response->json());
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
     * @param WhatsappChat $chat
     * @param int          $limit
     *
     * @return \Illuminate\Support\Collection<int, WhatsappChatMessage>
     */
    private function fetch_recent_messages(WhatsappChat $chat, int $limit)
    {
        return WhatsappChatMessage::where('whatsapp_chat_id', $chat->id)
            ->orderBy('created_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values();
    }

    /**
     * Busca, de atrás para adelante, el texto del último mensaje entrante ('in', del
     * cliente) dentro del historial recibido. Es el texto que dispara la búsqueda RAG.
     *
     * @param \Illuminate\Support\Collection<int, WhatsappChatMessage> $history_messages
     *
     * @return string Vacío si no hay ningún mensaje entrante en el historial.
     */
    private function last_customer_message_body($history_messages): string
    {
        for ($index = $history_messages->count() - 1; $index >= 0; $index--) {
            $message = $history_messages->get($index);
            if ($message->direction === 'in') {
                return trim((string) $message->body);
            }
        }

        return '';
    }

    /**
     * Arma el system prompt final: personalidad configurable (o la default) primero,
     * reglas fijas no negociables después, y por último el nombre del negocio como
     * contexto adicional.
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

        $blocks = [$personality, self::FIXED_RULES];

        $business_name = $this->business_name($config);
        if ($business_name !== '') {
            $blocks[] = 'Nombre del negocio: '.$business_name.'.';
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
        if (! $config->relationLoaded('user')) {
            $config->load('user');
        }

        return trim((string) ($config->user->name ?? ''));
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
     * @param \Illuminate\Support\Collection<int, WhatsappChatMessage> $history_messages
     * @param array                                                    $articles
     *
     * @return array<int, array{role: string, content: string}>
     */
    private function build_messages_payload($history_messages, array $articles): array
    {
        $turns = [];

        foreach ($history_messages as $message) {
            $body = trim((string) $message->body);
            if ($body === '') {
                continue;
            }

            $role = $message->direction === 'in' ? 'user' : 'assistant';
            $last_index = count($turns) - 1;

            if ($last_index >= 0 && $turns[$last_index]['role'] === $role) {
                // Mismo rol que el turno anterior: se funde en un solo mensaje.
                $turns[$last_index]['content'] .= "\n".$body;
            } else {
                $turns[] = ['role' => $role, 'content' => $body];
            }
        }

        // La conversación tiene que arrancar en 'user' para la API de Anthropic; si el
        // historial (recortado a los últimos N) arranca con un turno 'assistant', se
        // descarta para no romper la alternancia.
        while (! empty($turns) && $turns[0]['role'] !== 'user') {
            array_shift($turns);
        }

        $catalog_block = $this->build_catalog_block($articles);
        $last_index = count($turns) - 1;

        if ($last_index >= 0 && $turns[$last_index]['role'] === 'user') {
            $turns[$last_index]['content'] .= "\n\n".$catalog_block;
        } else {
            // No hay ningún turno de usuario (caso borde: historial vacío tras el filtro
            // de arriba); se agrega el catálogo como único mensaje para poder llamar a la API.
            $turns[] = ['role' => 'user', 'content' => $catalog_block];
        }

        return $turns;
    }

    /**
     * Arma el bloque de texto del catálogo relevante (RAG) con nombre, precio y stock de
     * cada artículo, tal como lo consume el system/user prompt.
     *
     * @param array $articles Artículos devueltos por `search_similar_articles` (stdClass).
     *
     * @return string
     */
    private function build_catalog_block(array $articles): string
    {
        if (empty($articles)) {
            return 'Productos del catálogo más relevantes para la consulta: no se encontraron productos relevantes.';
        }

        $lines = [];
        foreach ($articles as $article) {
            $lines[] = $this->format_article_line($article);
        }

        return "Productos del catálogo más relevantes para la consulta:\n".implode("\n", $lines);
    }

    /**
     * Formatea un artículo del RAG como línea de texto plano ("- nombre | Precio: $x |
     * Stock: y"), aceptando tanto stdClass (lo que devuelve `search_similar_articles`)
     * como array, de forma defensiva.
     *
     * @param array|object $article
     *
     * @return string
     */
    private function format_article_line($article): string
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

        return $line;
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
