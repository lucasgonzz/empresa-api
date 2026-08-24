<?php

namespace App\Services\StockSuggestion;

use App\Http\Controllers\Helpers\AiTokenUsageHelper;
use App\Models\Address;
use App\Models\StockSuggestionArticle;
use App\Services\Traits\TonoDeRedaccionIa;
use Illuminate\Support\Facades\Http;

/**
 * Redacción del resumen en criollo de una sugerencia de stock terminada.
 *
 * La IA SOLO comunica: el prompt le entrega los agregados y el top priorizado
 * ya calculados, sin stock crudo ni fórmulas, así que no tiene con qué
 * recalcular nada. Cualquier falla acá degrada sin bloquear: la sugerencia
 * queda usable con la tabla completa y sin resumen.
 */
class ResumenIaService
{
    use TonoDeRedaccionIa;

    /** Techo de tokens de la respuesta (un resumen de 6 oraciones sobra). */
    const MAX_TOKENS = 700;

    /** Timeout de la llamada HTTP a Anthropic, en segundos. */
    const TIMEOUT_SEGUNDOS = 45;

    /** Cantidad de líneas priorizadas que viajan en el prompt. */
    const TOP_LINEAS_PROMPT = 10;

    /**
     * true si hay clave de Anthropic configurada. No tener IA contratada no es
     * un error: sin clave, el resumen simplemente no se pide.
     *
     * @return bool
     */
    public function hay_credenciales(): bool
    {
        return (string) config('services.anthropic.api_key') !== '';
    }

    /**
     * Arma el prompt (un solo mensaje user, texto plano): rol, datos ya
     * calculados, reglas duras y formato de salida.
     *
     * La parte de datos vive en armar_datos(); acá solo se le ponen las
     * instrucciones de redacción alrededor. La salida es byte por byte la
     * misma que antes de extraer ese método.
     *
     * @param \App\Models\StockSuggestion $suggestion Sugerencia terminada
     * @return string
     */
    public function armar_prompt($suggestion): string
    {
        $prompt = "Sos el encargado de depósito de un comercio argentino. Escribí un resumen breve y claro, tuteando, sobre los traslados de stock entre sucursales que el sistema ya calculó: qué conviene mover primero y por qué (menos días de cobertura = el stock se acaba antes).\n\n"
            . $this->armar_datos($suggestion) . "\n\n"
            . "Reglas:\n"
            . "- No recalcules ni inventes cantidades: usa exactamente estas.\n"
            . "- Maximo 6 oraciones.\n"
            . "- Sin listas ni markdown: texto corrido.\n"
            . "- No saludes ni te presentes.\n"
            . $this->reglas_de_tono() . "\n\n"
            . "Responde solo con el texto plano del resumen.";

        return $prompt;
    }

    /**
     * Bloque de DATOS ya calculados de la sugerencia (agregados + top
     * priorizado), sin instrucciones de redacción.
     *
     * Público porque el chat del asistente de IA lo guarda tal cual en
     * ai_conversations.contexto: es el fondo sobre el que la conversación
     * de una sugerencia puede charlar sin recalcular nada.
     *
     * @param \App\Models\StockSuggestion $suggestion Sugerencia terminada
     * @return string
     */
    public function armar_datos($suggestion): string
    {
        $base = StockSuggestionArticle::where('stock_suggestion_id', $suggestion->id);

        $total_lineas = (clone $base)->count();
        $articulos_distintos = (clone $base)->distinct()->count('article_id');
        $unidades_totales = (float) (clone $base)->sum('suggested_amount');

        // Totales por par origen→destino, con el nombre real de cada sucursal.
        $pares = (clone $base)
            ->selectRaw('from_address_id, to_address_id, SUM(suggested_amount) as total')
            ->groupBy('from_address_id', 'to_address_id')
            ->orderByRaw('SUM(suggested_amount) DESC')
            ->get();

        $address_ids = $pares->pluck('from_address_id')
            ->merge($pares->pluck('to_address_id'))
            ->unique()
            ->filter()
            ->values();

        $nombres = Address::whereIn('id', $address_ids)->pluck('street', 'id');

        $lineas_pares = [];
        foreach ($pares as $par) {
            $lineas_pares[] = '- '
                . $this->nombre_sucursal($nombres, $par->from_address_id)
                . ' -> '
                . $this->nombre_sucursal($nombres, $par->to_address_id)
                . ': ' . $this->formatear_cantidad($par->total) . ' unidades';
        }

        // Top priorizado: el orden ya está materializado en la columna.
        $top = StockSuggestionArticle::with(['article', 'from_address', 'to_address'])
            ->where('stock_suggestion_id', $suggestion->id)
            ->whereNotNull('prioridad')
            ->orderBy('prioridad', 'ASC')
            ->limit(self::TOP_LINEAS_PROMPT)
            ->get();

        $lineas_top = [];
        foreach ($top as $linea) {
            $articulo = $linea->article && !empty($linea->article->name) ? $linea->article->name : 'Articulo ' . $linea->article_id;
            $desde = $linea->from_address && !empty($linea->from_address->street) ? $linea->from_address->street : 'Sucursal ' . $linea->from_address_id;
            $hasta = $linea->to_address && !empty($linea->to_address->street) ? $linea->to_address->street : 'Sucursal ' . $linea->to_address_id;

            $cobertura = $linea->cobertura_dias !== null
                ? 'cobertura ' . $this->formatear_cantidad($linea->cobertura_dias) . ' dias'
                : 'sin ventas recientes';

            $lineas_top[] = '- ' . $articulo
                . ' | de ' . $desde . ' a ' . $hasta
                . ' | ' . $this->formatear_cantidad($linea->suggested_amount) . ' unidades'
                . ' | ' . $cobertura;
        }

        return "Datos ya calculados:\n"
            . "- Lineas de traslado sugeridas: " . $total_lineas . "\n"
            . "- Articulos distintos: " . $articulos_distintos . "\n"
            . "- Unidades totales a mover: " . $this->formatear_cantidad($unidades_totales) . "\n"
            . "- Totales por par de sucursales:\n"
            . (count($lineas_pares) ? implode("\n", $lineas_pares) : '- (sin pares)') . "\n"
            . "- Los traslados mas urgentes, por dias hasta quedarse sin stock:\n"
            . (count($lineas_top) ? implode("\n", $lineas_top) : '- (sin lineas priorizadas)');
    }

    /**
     * Pide el resumen a la API de Anthropic. Modelo desde config, nunca
     * hardcodeado (misma práctica que WhatsappBotAiService, a diferencia de
     * los servicios viejos que clavaban el modelo en una constante).
     *
     * Retrofit de metering (misión chat-ia-y-modulo-ia): cuando llega
     * $user_id se registra el consumo de tokens leído del `usage` de la
     * respuesta. Parámetros opcionales para no tocar a ningún llamador que
     * solo quiera el texto; sin $user_id no se graba nada (no hay a quién
     * imputar el gasto).
     *
     * @param string $prompt
     * @param int|null $user_id Dueño de la cuenta, para el registro de tokens
     * @param int|null $suggestion_id Sugerencia de origen (referencia_id del registro)
     * @return string Texto del resumen
     *
     * @throws \RuntimeException Si la llamada falla o la API devuelve error
     */
    public function pedir_resumen(string $prompt, $user_id = null, $suggestion_id = null): string
    {
        $api_key = (string) config('services.anthropic.api_key');

        if ($api_key === '') {
            throw new \RuntimeException('La clave ANTHROPIC_API_KEY no está configurada en el entorno.');
        }

        $http = $this->build_anthropic_http_client($api_key);

        $response = $http->post('https://api.anthropic.com/v1/messages', [
            'model'      => (string) config('services.anthropic.model'),
            'max_tokens' => self::MAX_TOKENS,
            'messages'   => [
                [
                    'role'    => 'user',
                    'content' => $prompt,
                ],
            ],
        ]);

        if (!$response->successful()) {
            /*
             * Mismo manejo de errores transitorios que AiExcelAnalyzer::call_claude():
             * overloaded_error / api_error / HTTP 529 reciben un mensaje amigable.
             */
            $error_body = $response->json();
            $error_type = $error_body['error']['type'] ?? null;

            $transient_error_types = ['overloaded_error', 'api_error'];

            if (in_array($error_type, $transient_error_types) || $response->status() === 529) {
                throw new \RuntimeException(
                    'El servicio de IA no está disponible en este momento. Esperá unos segundos y volvé a intentarlo.'
                );
            }

            throw new \RuntimeException(
                'Error al comunicarse con Claude API (HTTP ' . $response->status() . '): ' . $response->body()
            );
        }

        $response_data = $response->json();

        // El registro nunca lanza: una falla de metering no voltea el resumen.
        if (! is_null($user_id)) {
            AiTokenUsageHelper::registrar([
                'user_id'       => $user_id,
                'proceso'       => 'resumen_sugerencia_stock',
                'modelo'        => (string) config('services.anthropic.model'),
                'body'          => is_array($response_data) ? $response_data : [],
                'referencia_id' => $suggestion_id,
            ]);
        }

        $text = $response_data['content'][0]['text'] ?? null;

        if (is_null($text)) {
            throw new \RuntimeException('Claude devolvió una respuesta sin contenido de texto.');
        }

        return trim($text);
    }

    /**
     * Cliente HTTP hacia Anthropic con headers y TLS (verify_ssl / ca_bundle
     * de config/services.php), mismo criterio que AiExcelAnalyzer.
     *
     * @param string $api_key
     * @return \Illuminate\Http\Client\PendingRequest
     */
    protected function build_anthropic_http_client(string $api_key)
    {
        $http = Http::withHeaders([
            'x-api-key'         => $api_key,
            'anthropic-version' => '2023-06-01',
            'content-type'      => 'application/json',
        ])->timeout(self::TIMEOUT_SEGUNDOS);

        $verify_ssl = (bool) config('services.anthropic.verify_ssl', true);
        $ca_bundle  = config('services.anthropic.ca_bundle');

        if (!$verify_ssl) {
            $http = $http->withoutVerifying();
        } elseif (is_string($ca_bundle) && $ca_bundle !== '' && is_file($ca_bundle)) {
            $http = $http->withOptions(['verify' => $ca_bundle]);
        }

        return $http;
    }

    /**
     * Nombre legible de una sucursal para el prompt.
     *
     * @param \Illuminate\Support\Collection $nombres Mapa id => street
     * @param int|null $address_id
     * @return string
     */
    protected function nombre_sucursal($nombres, $address_id)
    {
        if ($address_id && isset($nombres[$address_id]) && $nombres[$address_id] !== '') {
            return $nombres[$address_id];
        }

        return 'Sucursal ' . ($address_id ? $address_id : '?');
    }

    /**
     * Cantidades sin decimales de relleno (5 y no 5.00, 1.5 queda 1.5).
     *
     * @param mixed $valor
     * @return string
     */
    protected function formatear_cantidad($valor)
    {
        $float = (float) $valor;

        if ($float == (int) $float) {
            return (string) (int) $float;
        }

        return rtrim(rtrim(number_format($float, 2, '.', ''), '0'), '.');
    }
}
