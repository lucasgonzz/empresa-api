<?php

namespace App\Services\PurchaseSuggestion;

use App\Http\Controllers\Helpers\AiTokenUsageHelper;
use App\Models\PurchaseSuggestionArticle;
use Illuminate\Support\Facades\Http;

/**
 * Redacción del resumen en criollo de una sugerencia de compra terminada.
 *
 * Molde exacto de App\Services\StockSuggestion\ResumenIaService: la IA SOLO
 * comunica sobre datos YA CALCULADOS (agregados + contexto financiero + top
 * priorizado, todos ya persistidos por PurchaseSuggestionService /
 * ContextoFinancieroService / PrioridadComprasService), nunca recibe stock,
 * costos crudos ni fórmulas — no tiene con qué inventar ni recalcular nada.
 * Cualquier falla acá degrada sin bloquear: la sugerencia queda usable con
 * su tabla completa y sin resumen.
 */
class ResumenIaComprasService
{
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
     * calculados y reglas duras de redacción.
     *
     * La parte de datos vive en armar_datos(); acá solo se le ponen las
     * instrucciones de redacción alrededor — nunca al revés (hay un test que
     * blinda que armar_datos() no contenga ni una palabra de esto).
     *
     * @param \App\Models\PurchaseSuggestion $suggestion Sugerencia terminada
     * @return string
     */
    public function armar_prompt($suggestion): string
    {
        $prompt = "Sos el encargado de compras de un comercio argentino. Escribí un resumen breve y ameno, tuteando en rioplatense, sobre la sugerencia de compra a proveedores que el sistema ya calculó: qué conviene comprar primero, a quién y si conviene cambiar de proveedor en algún caso.\n\n"
            . $this->armar_datos($suggestion) . "\n\n"
            . "Reglas:\n"
            . "- No recalcules ni inventes cantidades ni costos: usa exactamente estos.\n"
            . "- Comentá si la deuda con el proveedor o la plata en caja hacen ruido, pero aclarando que las cantidades no se ajustaron por eso.\n"
            . "- Maximo 6 oraciones.\n"
            . "- Sin listas ni markdown: texto corrido.\n"
            . "- No saludes ni te presentes.\n\n"
            . "Responde solo con el texto plano del resumen.";

        return $prompt;
    }

    /**
     * Bloque de DATOS ya calculados de la sugerencia (agregados + contexto
     * financiero + top priorizado), sin instrucciones de redacción.
     *
     * Público porque el chat del asistente de IA lo guarda tal cual en
     * ai_conversations.contexto: es el fondo sobre el que la conversación de
     * una sugerencia puede charlar sin recalcular nada.
     *
     * 🔴 Acá NUNCA va una instrucción de redacción (eso vive en
     * armar_prompt()): hay un test dedicado (molde de
     * tests/Feature/ChatIa/9_Conversacion_de_sugerencia_Test.php:302-322) que
     * blinda que este método devuelva SOLO datos.
     *
     * @param \App\Models\PurchaseSuggestion $suggestion Sugerencia terminada
     * @return string
     */
    public function armar_datos($suggestion): string
    {
        $base = PurchaseSuggestionArticle::where('purchase_suggestion_id', $suggestion->id);

        $total_lineas = (clone $base)->count();
        $articulos_distintos = (clone $base)->distinct()->count('article_id');
        $ahorro_total = (float) (clone $base)->sum('ahorro_estimado');
        $total_estimado = $suggestion->total_estimado !== null ? (float) $suggestion->total_estimado : 0.0;

        $contexto_financiero = $this->decodificar_contexto_financiero($suggestion);
        $caja_disponible = isset($contexto_financiero['caja_disponible_pesos'])
            ? (float) $contexto_financiero['caja_disponible_pesos']
            : 0.0;
        $proveedores = isset($contexto_financiero['proveedores']) && is_array($contexto_financiero['proveedores'])
            ? $contexto_financiero['proveedores']
            : [];

        $lineas_proveedores = [];
        foreach ($proveedores as $proveedor) {
            $nombre = !empty($proveedor['nombre']) ? $proveedor['nombre'] : ('Proveedor ' . (isset($proveedor['provider_id']) ? $proveedor['provider_id'] : '?'));
            $deuda_pesos = isset($proveedor['deuda_pesos']) ? (float) $proveedor['deuda_pesos'] : 0.0;
            $deuda_dolares = isset($proveedor['deuda_dolares']) ? (float) $proveedor['deuda_dolares'] : 0.0;
            $total_proveedor = isset($proveedor['total_estimado']) ? (float) $proveedor['total_estimado'] : 0.0;

            $lineas_proveedores[] = '- ' . $nombre
                . ': se le compraria ' . $this->formatear_moneda($total_proveedor)
                . ', deuda actual ' . $this->formatear_moneda($deuda_pesos) . ' + USD ' . $this->formatear_cantidad($deuda_dolares);
        }

        // Top priorizado: el orden ya está materializado en la columna.
        $top = PurchaseSuggestionArticle::with(['article', 'provider'])
            ->where('purchase_suggestion_id', $suggestion->id)
            ->whereNotNull('prioridad')
            ->orderBy('prioridad', 'ASC')
            ->limit(self::TOP_LINEAS_PROMPT)
            ->get();

        $lineas_top = [];
        foreach ($top as $linea) {
            $articulo = $linea->article && !empty($linea->article->name) ? $linea->article->name : 'Articulo ' . $linea->article_id;
            $proveedor_nombre = $linea->provider && !empty($linea->provider->name) ? $linea->provider->name : 'sin proveedor asignado';

            $cobertura = $linea->cobertura_dias !== null
                ? 'cobertura ' . $this->formatear_cantidad($linea->cobertura_dias) . ' dias'
                : 'sin ventas recientes';

            $costo = $linea->costo_estimado !== null
                ? $this->formatear_moneda((float) $linea->costo_estimado)
                : 'sin costo de referencia';

            $lineas_top[] = '- ' . $articulo
                . ' | proveedor: ' . $proveedor_nombre
                . ' | ' . $this->formatear_cantidad($linea->cantidad_sugerida) . ' unidades'
                . ' | costo unitario ' . $costo
                . ' | ' . $cobertura;
        }

        return "Datos ya calculados:\n"
            . "- Lineas sugeridas: " . $total_lineas . "\n"
            . "- Articulos distintos: " . $articulos_distintos . "\n"
            . "- Total estimado de la compra: " . $this->formatear_moneda($total_estimado) . "\n"
            . "- Ahorro estimado total por elegir el proveedor mas conveniente: " . $this->formatear_moneda($ahorro_total) . "\n"
            . "- Disponible en cajas (pesos): " . $this->formatear_moneda($caja_disponible) . "\n"
            . "- Totales y deuda por proveedor:\n"
            . (count($lineas_proveedores) ? implode("\n", $lineas_proveedores) : '- (sin proveedores)') . "\n"
            . "- Las lineas mas urgentes, por prioridad:\n"
            . (count($lineas_top) ? implode("\n", $lineas_top) : '- (sin lineas priorizadas)');
    }

    /**
     * Pide el resumen a la API de Anthropic. Modelo desde config, nunca
     * hardcodeado.
     *
     * Metering: cuando llega $user_id se registra el consumo de tokens leído
     * del `usage` de la respuesta, con proceso 'resumen_sugerencia_compra'
     * (AiTokenUsageHelper::registrar() nunca lanza).
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
             * Mismo manejo de errores transitorios que ResumenIaService::pedir_resumen():
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
                'proceso'       => 'resumen_sugerencia_compra',
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
     * de config/services.php), mismo criterio que ResumenIaService.
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
     * Decodifica contexto_financiero (JSON guardado por
     * ContextoFinancieroService::armar()) a un array PHP. Nunca lanza: un
     * JSON corrupto o ausente (corrida sin proveedores, o todavía sin
     * cerrar) degrada a "sin datos", no a un error 500 del resumen.
     *
     * @param \App\Models\PurchaseSuggestion $suggestion
     * @return array
     */
    protected function decodificar_contexto_financiero($suggestion): array
    {
        $crudo = (string) ($suggestion->contexto_financiero ?? '');

        if ($crudo === '') {
            return [];
        }

        $decodificado = json_decode($crudo, true);

        return is_array($decodificado) ? $decodificado : [];
    }

    /**
     * Montos en pesos, formateados sin decimales de relleno.
     *
     * @param float $valor
     * @return string
     */
    protected function formatear_moneda(float $valor): string
    {
        return '$' . $this->formatear_cantidad($valor);
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
