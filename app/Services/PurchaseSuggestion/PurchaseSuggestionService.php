<?php

namespace App\Services\PurchaseSuggestion;

use App\Models\Article;
use App\Models\PurchaseSuggestion;
use App\Services\Compras\OfertasDeProveedorService;
use App\Services\StockSuggestion\CoberturaService;

/**
 * Motor de la sugerencia de compra: decide QUÉ artículos reponer, CUÁNTO
 * comprar de cada uno y A QUIÉN conviene comprarle, para un lote (chunk) de
 * artículos de una corrida (App\Models\PurchaseSuggestion).
 *
 * Dos disparadores independientes (entra si dispara cualquiera de los dos):
 * cobertura por debajo del punto de pedido, o stock por debajo del mínimo
 * cargado. La cantidad final es el máximo de lo que pide cada disparador
 * porque son razones distintas de comprar y no se suman: un repuesto de baja
 * rotación con mínimo cargado puede no vender hace 90 días (cobertura
 * infinita, no dispara por velocidad) y aun así hay que reponerlo hasta el
 * mínimo.
 *
 * La elección de proveedor es un problema aparte del de cantidad: compara el
 * costo vigente de cada proveedor con oferta en pesos
 * (OfertasDeProveedorService) contra el costo de referencia del proveedor
 * titular, y nunca mezcla monedas (las ofertas en dólares se guardan y se
 * muestran, pero acá no compiten — decisión R6 del plan: comparar pesos
 * contra dólares sin cotización es la clase de error de plata que este
 * proyecto ya pagó caro).
 *
 * 🔴 La plata (deuda con el proveedor, saldo de cajas) NO participa de nada
 * acá adentro: ese es el rol de ContextoFinancieroService, que corre después
 * y por separado (D9). Este servicio ni siquiera lo conoce.
 */
class PurchaseSuggestionService
{
    /** Días de cobertura por debajo de los cuales el artículo entra por el disparador de velocidad */
    const DIAS_PUNTO_PEDIDO = 15;

    /** Días de cobertura que la cantidad sugerida busca cubrir hacia adelante */
    const DIAS_COBERTURA_OBJETIVO = 30;

    /** Días de demora estimada del proveedor en entregar, sumados al objetivo de cobertura */
    const DIAS_LEAD_TIME = 7;

    /** Ventana de vigencia de una oferta de precio para competir por "más barato" */
    const DIAS_VIGENCIA_OFERTA = 120;

    /** Moneda que compite por precio más bajo (1 = Peso). Las de moneda_id = 2 se muestran, no compiten (R6) */
    const MONEDA_QUE_COMPITE = 1;

    /** Tamaño de lote de lectura de artículos con relaciones (memoria: with('addresses','providers') sobre miles de filas) */
    const LOTE_LECTURA_ARTICULOS = 50;

    /** @var PurchaseSuggestion Cabecera de la corrida (dueño y los cuatro parámetros del algoritmo) */
    protected $suggestion;

    /**
     * @param PurchaseSuggestion $suggestion
     */
    public function __construct($suggestion)
    {
        $this->suggestion = $suggestion;
    }

    /**
     * Calcula las líneas de sugerencia para un lote de artículos (un chunk de
     * la corrida). Devuelve arrays listos para insertar, a los que el
     * llamador (ProcessPurchaseSuggestionChunkJob) solo tiene que agregarle
     * purchase_suggestion_id/prioridad/timestamps antes del bulk insert.
     *
     * @param array $article_ids
     * @return array Lista de ['article_id','provider_id','provider_id_titular','cantidad_sugerida','stock_global','stock_min_global','velocidad_diaria','cobertura_dias','costo_estimado','costo_proveedor_titular','ahorro_estimado','oferta_fecha']
     */
    public function calcular_para_articulos(array $article_ids): array
    {
        $lineas = [];

        if (empty($article_ids)) {
            return $lineas;
        }

        $cobertura_service = new CoberturaService($this->suggestion->user_id);

        // Una sola consulta agregada para TODO el chunk (nunca una por
        // artículo), con el método HERMANO que mezcla las ventas de todas
        // las sucursales de una: la mezcla 2/3-1/3 de CoberturaService no es
        // aditiva entre sucursales (branch "si hay interanual" adentro de
        // mezclar_velocidades()), así que sumar la velocidad calculada
        // aparte para cada dirección destino daría un número distinto de
        // mezclar de una las ventas de todas las sucursales juntas. Acá
        // tampoco hay sucursal destino (es reposición global del comercio,
        // no traslado entre depósitos como en stock): hay que pedirle la
        // velocidad global directamente al servicio, no reconstruirla
        // sumando cálculos por sucursal.
        $velocidades = $cobertura_service->velocidades_globales($article_ids);

        $dias_vigencia_oferta = (int) ($this->suggestion->dias_vigencia_oferta ?? self::DIAS_VIGENCIA_OFERTA);

        // Ídem: una sola query para todo el chunk, nunca una por artículo.
        $ofertas = OfertasDeProveedorService::mejores_ofertas_para(
            $article_ids,
            $this->suggestion->user_id,
            $dias_vigencia_oferta
        );

        // with('providers'): necesario para leer article_provider.cost del
        // par (titular, artículo) sin una query aparte por artículo — es el
        // costo de referencia del titular cuando no tiene oferta vigente en
        // pesos (ver costo_de_referencia_titular()).
        Article::where('user_id', $this->suggestion->user_id)
            ->where('status', '!=', 'inactive')
            ->whereIn('id', $article_ids)
            ->with(['addresses', 'providers'])
            ->chunk(self::LOTE_LECTURA_ARTICULOS, function ($articulos) use (&$lineas, $cobertura_service, $velocidades, $ofertas) {
                foreach ($articulos as $article) {
                    $linea = $this->calcular_para_articulo($article, $cobertura_service, $velocidades, $ofertas);

                    if ($linea !== null) {
                        $lineas[] = $linea;
                    }
                }
            });

        return $lineas;
    }

    /**
     * Calcula la línea de un artículo, o null si ninguno de los dos
     * disparadores aplica (el artículo no entra a la sugerencia).
     *
     * @param Article $article
     * @param CoberturaService $cobertura_service
     * @param array $velocidades Mapa clave_global() => velocidad diaria, para TODO el chunk
     * @param array $ofertas Salida de OfertasDeProveedorService::mejores_ofertas_para(), para TODO el chunk
     * @return array|null
     */
    protected function calcular_para_articulo(Article $article, CoberturaService $cobertura_service, array $velocidades, array $ofertas): ?array
    {
        [$stock_global, $stock_min_global] = $this->calcular_stock_global($article);

        $clave = $cobertura_service->clave_global($article->id);
        $velocidad = isset($velocidades[$clave]) ? $velocidades[$clave] : 0.0;
        $cobertura_dias = $cobertura_service->cobertura($stock_global, $velocidad);

        $dias_punto_pedido = (int) ($this->suggestion->dias_punto_pedido ?? self::DIAS_PUNTO_PEDIDO);
        $dias_cobertura_objetivo = (int) ($this->suggestion->dias_cobertura_objetivo ?? self::DIAS_COBERTURA_OBJETIVO);
        $dias_lead_time = (int) ($this->suggestion->dias_lead_time ?? self::DIAS_LEAD_TIME);

        $dispara_cobertura = $cobertura_dias !== null && $cobertura_dias <= $dias_punto_pedido;
        $dispara_minimo = $stock_min_global !== null && $stock_global < $stock_min_global;

        // Ninguno de los dos disparadores: el artículo no entra a la sugerencia.
        if (!$dispara_cobertura && !$dispara_minimo) {
            return null;
        }

        // El disparador que no aplicó aporta 0 al max(): no es que "no pide
        // nada", es que ESA razón puntual no tiene nada para pedir — la otra
        // puede seguir pidiendo igual.
        $cantidad_velocidad = $dispara_cobertura
            ? ($velocidad * ($dias_cobertura_objetivo + $dias_lead_time) - $stock_global)
            : 0.0;

        $cantidad_minimo = $dispara_minimo
            ? ($stock_min_global - $stock_global)
            : 0.0;

        $cantidad_sugerida = (float) max(ceil(max($cantidad_velocidad, $cantidad_minimo)), 1);

        $titular_id = $article->provider_id ? (int) $article->provider_id : null;
        $ofertas_articulo = isset($ofertas[$article->id]) ? $ofertas[$article->id] : [];

        // Solo compiten las ofertas en la misma moneda que el resto del
        // cálculo (pesos): las de moneda_id = 2 existen y se muestran en
        // otro lado, pero comparar pesos contra dólares sin cotización es la
        // clase de error de plata que este proyecto ya pagó caro (R6).
        $ofertas_moneda1 = [];
        foreach ($ofertas_articulo as $provider_id => $oferta) {
            if ((int) $oferta['moneda_id'] === self::MONEDA_QUE_COMPITE) {
                $ofertas_moneda1[$provider_id] = $oferta;
            }
        }

        $costo_referencia_titular = $this->costo_de_referencia_titular($article, $titular_id);
        $eleccion = $this->elegir_proveedor($titular_id, $ofertas_moneda1, $costo_referencia_titular);

        $ahorro_estimado = null;

        // Null salvo que haya los dos costos Y el elegido no sea el titular:
        // sin costo de alguno de los dos lados no hay ahorro que calcular, y
        // si el elegido ES el titular no hay "ahorro" (es el mismo proveedor
        // de siempre).
        if (
            $eleccion['costo_proveedor_titular'] !== null
            && $eleccion['costo_estimado'] !== null
            && $eleccion['provider_id'] !== $eleccion['provider_id_titular']
        ) {
            $ahorro_estimado = ($eleccion['costo_proveedor_titular'] - $eleccion['costo_estimado']) * $cantidad_sugerida;
        }

        return [
            'article_id' => $article->id,
            'provider_id' => $eleccion['provider_id'],
            'provider_id_titular' => $eleccion['provider_id_titular'],
            'cantidad_sugerida' => $cantidad_sugerida,
            'stock_global' => $stock_global,
            'stock_min_global' => $stock_min_global,
            'velocidad_diaria' => round($velocidad, 4),
            'cobertura_dias' => $cobertura_dias,
            'costo_estimado' => $eleccion['costo_estimado'],
            'costo_proveedor_titular' => $eleccion['costo_proveedor_titular'],
            'ahorro_estimado' => $ahorro_estimado,
            'oferta_fecha' => $eleccion['oferta_fecha'],
        ];
    }

    /**
     * Stock global (todas las sucursales sumadas) y stock mínimo global
     * (suma de los mínimos cargados; null si NINGUNA sucursal tiene mínimo
     * cargado — no es que el mínimo sea 0, es que no está definido en
     * ninguna, y 0 dispararía siempre por "stock < mínimo" con stock 0).
     *
     * @param Article $article Con 'addresses' precargado (withPivot amount, stock_min)
     * @return array [stock_global (float), stock_min_global (float|null)]
     */
    protected function calcular_stock_global(Article $article): array
    {
        $stock_global = 0.0;
        $stock_min_global = 0.0;
        $tiene_stock_min = false;

        foreach ($article->addresses as $address) {
            $pivot = $address->pivot;

            $stock_global += $pivot->amount !== null ? (float) $pivot->amount : 0.0;

            if ($pivot->stock_min !== null) {
                $stock_min_global += (float) $pivot->stock_min;
                $tiene_stock_min = true;
            }
        }

        return [$stock_global, $tiene_stock_min ? $stock_min_global : null];
    }

    /**
     * Costo de referencia del proveedor titular (articles.provider_id) para
     * cuando no tiene una oferta vigente en pesos: primero
     * article_provider.cost del par (el estado actual del pivot), y si no
     * hay, articles.cost (el costo genérico del artículo). Null si ninguno
     * de los dos está cargado o son <= 0 — un costo en cero no es un costo
     * real (misma guarda que OfertasDeProveedorService::registrar_lote()).
     *
     * @param Article $article Con 'providers' precargado (withPivot cost)
     * @param int|null $titular_id
     * @return float|null
     */
    protected function costo_de_referencia_titular(Article $article, ?int $titular_id): ?float
    {
        if ($titular_id === null) {
            return null;
        }

        $relacion = $article->providers->firstWhere('id', $titular_id);

        if ($relacion && $relacion->pivot->cost !== null && (float) $relacion->pivot->cost > 0) {
            return (float) $relacion->pivot->cost;
        }

        if ($article->cost !== null && (float) $article->cost > 0) {
            return (float) $article->cost;
        }

        return null;
    }

    /**
     * Elige a quién conviene comprarle, en el orden exacto del plan:
     * 1. Candidatos = proveedores con oferta vigente en pesos + el titular
     *    (con su costo de referencia, aunque no tenga oferta).
     * 2. Gana el de menor costo.
     * 3. Empate exacto → gana el titular; si ninguno de los empatados es el
     *    titular, gana el de menor provider_id (determinismo entre
     *    corridas: misma foto de datos, mismo resultado, sin depender del
     *    orden de llegada de las filas).
     * 4. Sin oferta vigente ni titular con costo → gana el titular igual,
     *    con costo_estimado null (la línea queda, sin precio para mostrar).
     * 5. Sin titular y sin ofertas → provider_id null: "sin proveedor
     *    asignado". La línea NO se descarta: el usuario necesita ver que le
     *    falta comprar eso.
     *
     * @param int|null $titular_id
     * @param array $ofertas_moneda1 [provider_id => ['cost'=>float,'fecha'=>string,'origen'=>string,'moneda_id'=>int]]
     * @param float|null $costo_referencia_titular
     * @return array ['provider_id'=>int|null,'costo_estimado'=>float|null,'oferta_fecha'=>string|null,'provider_id_titular'=>int|null,'costo_proveedor_titular'=>float|null]
     */
    protected function elegir_proveedor(?int $titular_id, array $ofertas_moneda1, ?float $costo_referencia_titular): array
    {
        // provider_id => ['cost' => float, 'fecha' => string|null]
        $candidatos = [];

        foreach ($ofertas_moneda1 as $provider_id => $oferta) {
            $candidatos[$provider_id] = ['cost' => $oferta['cost'], 'fecha' => $oferta['fecha']];
        }

        $costo_proveedor_titular = null;

        if ($titular_id !== null) {
            if (isset($candidatos[$titular_id])) {
                // El titular tiene oferta vigente en pesos: esa ES su costo
                // de referencia, más confiable (y más reciente) que el
                // estado del pivot.
                $costo_proveedor_titular = $candidatos[$titular_id]['cost'];
            } elseif ($costo_referencia_titular !== null) {
                $costo_proveedor_titular = $costo_referencia_titular;
                // Sin fecha: no viene de una oferta fechada del histórico,
                // viene del estado actual del pivot o de articles.cost.
                $candidatos[$titular_id] = ['cost' => $costo_referencia_titular, 'fecha' => null];
            }
        }

        if (empty($candidatos)) {
            // Reglas 4 y 5: el titular gana igual (provider_id null cubre la
            // regla 5, sin titular y sin ofertas).
            return [
                'provider_id' => $titular_id,
                'costo_estimado' => null,
                'oferta_fecha' => null,
                'provider_id_titular' => $titular_id,
                'costo_proveedor_titular' => null,
            ];
        }

        $costo_minimo = null;

        foreach ($candidatos as $candidato) {
            if ($costo_minimo === null || $candidato['cost'] < $costo_minimo) {
                $costo_minimo = $candidato['cost'];
            }
        }

        $empatados = [];

        foreach ($candidatos as $provider_id => $candidato) {
            if ($candidato['cost'] == $costo_minimo) {
                $empatados[] = $provider_id;
            }
        }

        if (count($empatados) === 1) {
            $elegido = $empatados[0];
        } elseif ($titular_id !== null && in_array($titular_id, $empatados, true)) {
            $elegido = $titular_id;
        } else {
            sort($empatados);
            $elegido = $empatados[0];
        }

        return [
            'provider_id' => $elegido,
            'costo_estimado' => $candidatos[$elegido]['cost'],
            'oferta_fecha' => $candidatos[$elegido]['fecha'],
            'provider_id_titular' => $titular_id,
            'costo_proveedor_titular' => $costo_proveedor_titular,
        ];
    }
}
