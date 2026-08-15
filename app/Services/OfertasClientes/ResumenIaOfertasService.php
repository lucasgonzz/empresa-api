<?php

namespace App\Services\OfertasClientes;

use App\Http\Controllers\Helpers\AiTokenUsageHelper;
use App\Models\OfferSuggestionLine;
use Illuminate\Support\Facades\Http;

/**
 * Lo que la IA recibe y lo que la IA decide en el motor de ofertas (§A.7). Molde exacto de
 * ResumenIaComprasService.
 *
 * 🔴 LA IA NUNCA RECALCULA PLATA. Recibe números YA CALCULADOS —el techo, el piso y el porcentaje
 * determinista salen de TechoDeDescuentoService y PorcentajeSugeridoService antes de que exista este
 * prompt— y lo único que hace es elegir un número ADENTRO del rango y explicarlo en criollo. No ve
 * costos, no ve márgenes y no tiene ninguna fórmula con qué inventar un descuento. Y aunque devuelva
 * un absurdo, OfertaSugeridaService::aplicar_decision_de_la_ia() lo recorta igual: el prompt es una
 * sugerencia, el recorte es la garantía.
 *
 * 🔴 armar_datos() y armar_prompt() están SEPARADOS y no es cosmético: armar_datos() es lo que el
 * chat del asistente guarda tal cual en ai_conversations.contexto, así que ahí NUNCA puede ir una
 * instrucción de redacción (si no, la conversación hereda la orden de escribir un resumen en vez de
 * charlar sobre los datos). Hay un test dedicado que lo blinda.
 *
 * Cualquier falla acá degrada sin bloquear: la corrida queda 'terminado' con su tabla completa y los
 * porcentajes deterministas, y el resumen queda en null (sin credenciales) o 'error' (falló).
 * PHP 7.4: sin match, ?->, str_contains ni #[...].
 */
class ResumenIaOfertasService
{
    /** Techo de tokens: más alto que el de compras (700) porque la respuesta trae una decisión por línea. */
    const MAX_TOKENS = 2500;

    /** Timeout de la llamada HTTP a Anthropic, en segundos. */
    const TIMEOUT_SEGUNDOS = 60;
    /** Cantidad de líneas priorizadas que viajan en el prompt y sobre las que la IA decide. */
    const TOP_LINEAS_PROMPT = 15;
    /** Vigencia mínima que se le permite proponer (el mismo piso que aplica el recorte). */
    const DIAS_VIGENCIA_MINIMOS = OfertaSugeridaService::DIAS_VIGENCIA_MINIMOS;

    /**
     * true si hay clave de Anthropic configurada. No tener IA contratada no es un error: sin clave el
     * resumen no se pide y la corrida cierra con los porcentajes deterministas.
     * @return bool
     */
    public function hay_credenciales(): bool
    {
        return (string) config('services.anthropic.api_key') !== '';
    }

    /**
     * Bloque de DATOS ya calculados de la corrida, sin una sola instrucción de redacción. Público
     * porque el chat del asistente lo guarda tal cual en ai_conversations.contexto: es el fondo sobre
     * el que la conversación puede charlar sin recalcular nada.
     *
     * @param  mixed $suggestion Corrida terminada
     * @return string
     */
    public function armar_datos($suggestion): string
    {
        $base = OfferSuggestionLine::where('offer_suggestion_id', $suggestion->id);

        $total_lineas = (clone $base)->count();
        $clientes     = (clone $base)->distinct()->count('client_id');
        $excluidos    = (int) $suggestion->total_clientes_excluidos_por_deuda;

        $por_criterio = [];
        foreach ((clone $base)->selectRaw('criterio, COUNT(*) as total')->groupBy('criterio')->get() as $fila) {
            $por_criterio[] = '- ' . str_replace('_', ' ', $fila->criterio) . ': ' . (int) $fila->total;
        }

        $lineas = [];
        foreach ($this->lineas_priorizadas($suggestion) as $linea) {
            $lineas[] = $this->describir_linea($linea);
        }

        $vigencia = (int) $suggestion->dias_vigencia_sugerida;

        return "Datos ya calculados:\n"
            . "- Ofertas sugeridas: " . $total_lineas . "\n"
            . "- Clientes alcanzados: " . $clientes . "\n"
            // 🔴 El conteo de excluidos va con su leyenda y no como número suelto: es la decisión de
            // Lucas del 15/8/2026 (al que debe hace mucho no se le ofrece nada) y es lo que le muestra
            // al comerciante que el sistema tuvo criterio. Un "3" suelto no significa nada.
            . "- " . $excluidos . " clientes quedaron afuera porque tienen ventas sin cobrar\n"
            . "- Ofertas por criterio de marketing:\n"
            . (count($por_criterio) ? implode("\n", $por_criterio) : '- (sin lineas)') . "\n"
            // El tope sale de OfertaSugeridaService y no de un ($vigencia * 2) escrito acá: es el
            // mismo número con el que aplicar_decision_de_la_ia() recorta y el que valida el
            // controller al activar. Si el prompt prometiera un rango más ancho, la IA elegiría una
            // fecha que el recorte le baja después sin decirle por qué.
            . "- Vigencia por defecto de la corrida: " . $vigencia . " dias"
            . " (el rango permitido va de " . self::DIAS_VIGENCIA_MINIMOS
            . " a " . OfertaSugeridaService::tope_de_vigencia($vigencia) . ")\n"
            . "- Las ofertas mas importantes, por prioridad:\n"
            . (count($lineas) ? implode("\n", $lineas) : '- (sin lineas priorizadas)');
    }

    /**
     * Rol, los datos ya calculados y las reglas duras de redacción y formato. La parte de datos vive
     * en armar_datos(); acá solo se le ponen las instrucciones alrededor, nunca al revés.
     *
     * @param  mixed $suggestion Corrida terminada
     * @return string
     */
    public function armar_prompt($suggestion): string
    {
        $vigencia = (int) $suggestion->dias_vigencia_sugerida;

        return "Sos el encargado de marketing de un comercio argentino. El sistema ya calculo a que cliente conviene ofrecerle que articulo y entre que valores puede ir el descuento de cada oferta. Vos elegis el numero final adentro de ese rango y contas en una frase por que.\n\n"
            . $this->armar_datos($suggestion) . "\n\n"
            . "Reglas:\n"
            . "- No recalcules ni inventes precios, costos ni margenes: el rango de cada oferta ya esta calculado y su maximo es todo lo que el margen del articulo tolera.\n"
            . "- El porcentaje de cada oferta tiene que quedar adentro del rango que dice su linea. Si te pasas, el sistema lo recorta igual.\n"
            . "- Dale mas descuento a lo que esta mas parado (vendibilidad alta) y menos a lo que sale solo.\n"
            . "- dias_vigencia tiene que ser un entero entre " . self::DIAS_VIGENCIA_MINIMOS
            . " y " . OfertaSugeridaService::tope_de_vigencia($vigencia) . ".\n"
            . "- El motivo de cada oferta: una frase corta, tuteando en rioplatense.\n"
            . "- El resumen: maximo 6 oraciones, tuteando en rioplatense, texto corrido, sin listas ni markdown, y sin saludar ni presentarte. Deci cuantos clientes quedaron afuera por tener ventas sin cobrar.\n\n"
            . "Responde SOLO con un JSON, sin ningun texto alrededor y sin cercas de codigo, con esta forma exacta:\n"
            . '{"resumen":"texto corrido","lineas":[{"id":123,"porcentaje":18,"dias_vigencia":12,"motivo":"texto corto"}]}';
    }

    /**
     * Decodifica la respuesta de la IA. Nunca lanza: devuelve null cuando el JSON no vino o no tiene
     * la forma esperada, y el job lo trata como falla del resumen dejando los deterministas.
     *
     * @param  string $texto
     * @return array|null ['resumen' => string, 'lineas' => array]
     */
    public function decodificar_respuesta($texto)
    {
        $limpio = trim((string) $texto);

        // Las cercas de código son el desvío más común de un modelo al que se le pide JSON pelado: se
        // sacan acá aunque el prompt ya las prohíba. substr y no str_starts_with: PHP 7.4.
        if (substr($limpio, 0, 3) === '```') {
            $limpio = preg_replace('/^```[a-zA-Z]*\s*/', '', $limpio);
            $limpio = preg_replace('/```\s*$/', '', $limpio);
            $limpio = trim($limpio);
        }

        $decodificado = json_decode($limpio, true);

        if (!is_array($decodificado) || !isset($decodificado['resumen']) || !is_string($decodificado['resumen'])) {
            return null;
        }

        return [
            'resumen' => trim($decodificado['resumen']),
            'lineas'  => isset($decodificado['lineas']) && is_array($decodificado['lineas'])
                ? $decodificado['lineas']
                : [],
        ];
    }

    /**
     * Pide la respuesta a la API de Anthropic. Modelo desde config, nunca hardcodeado. Con $user_id
     * registra el consumo de tokens con proceso 'sugerencia_oferta' (registrar() nunca lanza).
     *
     * @param  string   $prompt
     * @param  int|null $user_id       Dueño de la cuenta, para el registro de tokens
     * @param  int|null $suggestion_id Corrida de origen (referencia_id del registro)
     * @return string Texto crudo de la respuesta
     * @throws \RuntimeException Si la llamada falla o la API devuelve error
     */
    public function pedir_resumen(string $prompt, $user_id = null, $suggestion_id = null): string
    {
        $api_key = (string) config('services.anthropic.api_key');

        if ($api_key === '') {
            throw new \RuntimeException('La clave ANTHROPIC_API_KEY no está configurada en el entorno.');
        }

        $response = $this->build_anthropic_http_client($api_key)->post('https://api.anthropic.com/v1/messages', [
            'model'      => (string) config('services.anthropic.model'),
            'max_tokens' => self::MAX_TOKENS,
            'messages'   => [['role' => 'user', 'content' => $prompt]],
        ]);

        if (!$response->successful()) {
            /*
             * Mismo manejo de errores transitorios que ResumenIaComprasService::pedir_resumen():
             * overloaded_error / api_error / HTTP 529 reciben un mensaje amigable, porque son
             * "volvé a intentar en un rato" y no "algo está roto".
             */
            $error_body = $response->json();
            $error_type = isset($error_body['error']['type']) ? $error_body['error']['type'] : null;

            if (in_array($error_type, ['overloaded_error', 'api_error']) || $response->status() === 529) {
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
        if (!is_null($user_id)) {
            AiTokenUsageHelper::registrar([
                'user_id'       => $user_id,
                'proceso'       => 'sugerencia_oferta',
                'modelo'        => (string) config('services.anthropic.model'),
                'body'          => is_array($response_data) ? $response_data : [],
                'referencia_id' => $suggestion_id,
            ]);
        }

        $text = isset($response_data['content'][0]['text']) ? $response_data['content'][0]['text'] : null;

        if (is_null($text)) {
            throw new \RuntimeException('Claude devolvió una respuesta sin contenido de texto.');
        }

        return trim($text);
    }

    /**
     * Las líneas sobre las que decide la IA: el top por prioridad, ya materializada al cerrar.
     * @param  mixed $suggestion
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function lineas_priorizadas($suggestion)
    {
        return OfferSuggestionLine::with(['client', 'article'])
            ->where('offer_suggestion_id', $suggestion->id)
            ->whereNotNull('prioridad')
            ->orderBy('prioridad', 'ASC')
            ->limit(self::TOP_LINEAS_PROMPT)
            ->get();
    }

    /**
     * Una línea, en una fila de texto con TODO ya calculado y nada por calcular. 🔴 Las tres señales
     * del artículo (velocidad de venta, días sin venderse, días desde la última compra al proveedor)
     * viajan RESUMIDAS en `vendibilidad`, que es exactamente el número que PorcentajeSugeridoService
     * calculó con esas tres: mandarlas también crudas sería invitar a la IA a recomponer a mano un
     * cálculo ya hecho, que es justo lo que este diseño evita.
     *
     * @param  mixed $linea
     * @return string
     */
    protected function describir_linea($linea)
    {
        $cliente  = $linea->client && !empty($linea->client->name) ? $linea->client->name : ('Cliente ' . $linea->client_id);
        $articulo = $linea->article && !empty($linea->article->name) ? $linea->article->name : ('Articulo ' . $linea->article_id);

        $credito = is_null($linea->es_buen_pagador)
            ? 'sin datos de cuenta corriente'
            : ($linea->es_buen_pagador ? 'paga al dia' : 'tiene deuda');

        return '- id ' . $linea->id . ' | cliente: ' . $cliente . ' | articulo: ' . $articulo
            . ' | motivo: ' . str_replace('_', ' ', $linea->criterio) . ' (' . trim((string) $linea->criterio_detalle) . ')'
            . ' | descuento ya calculado ' . $this->formatear_cantidad($linea->porcentaje_sugerido) . '%'
            . ', rango permitido de ' . $this->formatear_cantidad($linea->porcentaje_piso) . '% a ' . $this->formatear_cantidad($linea->porcentaje_techo) . '%'
            . ' | vendibilidad ' . $this->formatear_cantidad($linea->vendibilidad) . ' de 1'
            . ' | ' . $credito . ' | descuento por ' . $linea->tipo_descuento;
    }

    /**
     * Cliente HTTP hacia Anthropic con headers y TLS (verify_ssl / ca_bundle), igual que compras.
     * @param  string $api_key
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
     * Números sin decimales de relleno (16 y no 16.00, 0.62 queda 0.62).
     * @param  mixed $valor
     * @return string
     */
    protected function formatear_cantidad($valor)
    {
        $float = (float) $valor;

        if ($float == (int) $float) {
            return (string) (int) $float;
        }

        return rtrim(rtrim(number_format($float, 3, '.', ''), '0'), '.');
    }
}
