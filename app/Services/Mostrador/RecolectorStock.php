<?php

namespace App\Services\Mostrador;

use App\Models\StockSuggestion;
use App\Models\User;
use App\Services\PurchaseSuggestion\PurchaseSuggestionService;
use App\Services\StockSuggestion\CoberturaService;
use App\Services\StockSuggestion\StockSuggestionService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Hechos del informe "Stock" (tipo 'stock'): los traslados sugeridos entre
 * sucursales, ordenados por urgencia, y lo que está sin rotación. Solo aplica a un
 * comercio con dos o más sucursales (addresses): con una sola no hay adónde mover.
 *
 * Los traslados son los del motor de sugerencias de stock (StockSuggestionService,
 * con el modo / origen / límite configurados por el dueño en users.sugerencias_*)
 * corrido en memoria sobre una cabecera SIN guardar: no se crea ninguna
 * stock_suggestion. La urgencia es la de CoberturaService: velocidad de venta de
 * 90 días en la sucursal destino (mezclada con la misma ventana del año anterior)
 * y días de cobertura del stock que hay ahí; prioridad 1 = la menor cobertura.
 *
 * Solo se le pasan al motor los artículos que PUEDEN tener un traslado (stock en
 * dos o más sucursales y algún mínimo o máximo cargado): el resto no tiene ni
 * origen ni objetivo, y el motor los descartaría igual.
 *
 * `valor_a_costo` y `valor_inmovilizado` van EN PESOS: un artículo con el costo cargado
 * en dólares (articles.cost_in_dollars) se cotiza como lo hace el sistema al fijar su
 * precio (ArticleHelper::cotizar, ProviderOrderHelper): con el dólar del proveedor
 * titular si lo tiene cargado, si no con el dólar de la cuenta (users.dollar). Sin
 * ninguna cotización cargada el costo se toma tal cual (no hay con qué convertir).
 */
class RecolectorStock extends RecolectorBase
{
    /** Tope de movimientos sugeridos. */
    const TOPE_MOVIMIENTOS = 30;

    /** Días sin ventas a partir de los cuales un artículo con stock está "sin rotación". */
    const DIAS_SIN_ROTACION = 90;

    /** Tamaño de lote de artículos que se le pasa al motor por vez. */
    const LOTE_CALCULO = 1000;

    /**
     * @param User $owner
     * @param Carbon $fecha El día del informe (hoy, por defecto)
     * @return array
     */
    public function recolectar(User $owner, Carbon $fecha): array
    {
        $sucursales = DB::table('addresses')
            ->where('user_id', $owner->id)
            ->orderBy('id')
            ->get(['id', 'street', 'es_deposito_origen']);

        if ($sucursales->count() < 2) {
            return $this->no_aplica($fecha, 'El comercio tiene una sola sucursal: no hay traslados de stock que sugerir.');
        }

        $nombres = [];
        $lista_sucursales = [];

        foreach ($sucursales as $sucursal) {
            $nombres[(int) $sucursal->id] = (string) $sucursal->street;

            $lista_sucursales[] = [
                'address_id'         => (int) $sucursal->id,
                'nombre'             => (string) $sucursal->street,
                'es_deposito_origen' => (bool) $sucursal->es_deposito_origen,
            ];
        }

        $movimientos = $this->movimientos_sugeridos($owner, $nombres);
        $sin_rotacion = $this->sin_rotacion($owner, $fecha);

        return [
            'aplica'                => true,
            'fecha'                 => $fecha->format('Y-m-d'),
            'sucursales'            => $lista_sucursales,
            'movimientos_sugeridos' => $movimientos['lista'],
            'sin_rotacion'          => $sin_rotacion['lista'],
            'resumen'               => [
                'articulos_en_riesgo' => $movimientos['articulos_en_riesgo'],
                'valor_inmovilizado'  => $sin_rotacion['valor_inmovilizado'],
            ],
        ];
    }

    /**
     * Artículos que recorrería el motor de traslados (ver RecolectorBase). Con menos de
     * dos sucursales el tipo no aplica y no se recorre nada.
     *
     * @param User $owner
     * @return int
     */
    public function cantidad_de_candidatos(User $owner): int
    {
        $sucursales = DB::table('addresses')->where('user_id', $owner->id)->count();

        if ($sucursales < 2) {
            return 0;
        }

        return count($this->articulos_candidatos($owner));
    }

    /**
     * Corre el motor de traslados en memoria y arma los movimientos con su urgencia.
     *
     * @param User $owner
     * @param array $nombres Mapa address_id => nombre
     * @return array{lista: array, articulos_en_riesgo: int}
     */
    protected function movimientos_sugeridos(User $owner, array $nombres): array
    {
        $vacio = ['lista' => [], 'articulos_en_riesgo' => 0];

        $candidatos = $this->articulos_candidatos($owner);

        if (empty($candidatos)) {
            return $vacio;
        }

        // Cabecera SIN guardar, con la configuración del dueño (mismos defaults que
        // el comando sugerencias:generar).
        $cabecera = new StockSuggestion([
            'user_id'       => $owner->id,
            'modo'          => $owner->sugerencias_modo ? $owner->sugerencias_modo : 'minimo',
            'origen'        => $owner->sugerencias_origen ? $owner->sugerencias_origen : 'absoluto',
            'limite_origen' => $owner->sugerencias_limite_origen ? $owner->sugerencias_limite_origen : 'minimo',
        ]);

        $motor = new StockSuggestionService($cabecera);
        $sugerencias = [];

        foreach (array_chunk($candidatos, self::LOTE_CALCULO) as $lote) {
            foreach ($motor->getSuggestionsForArticles($lote) as $sugerencia) {
                $sugerencias[] = $sugerencia;
            }
        }

        if (empty($sugerencias)) {
            return $vacio;
        }

        // Velocidad de venta en cada destino, una consulta por lote de pares.
        $cobertura_service = new CoberturaService($owner->id);
        $velocidades = [];

        $pares = [];

        foreach ($sugerencias as $sugerencia) {
            $pares[] = ['article_id' => $sugerencia['article_id'], 'address_id' => $sugerencia['to_address_id']];
        }

        foreach (array_chunk($pares, self::LOTE_CALCULO) as $lote) {
            $velocidades = $velocidades + $cobertura_service->velocidades_para($lote);
        }

        $articulos_en_riesgo = [];

        foreach ($sugerencias as $indice => $sugerencia) {
            $clave = $cobertura_service->clave_para($sugerencia['article_id'], $sugerencia['to_address_id']);
            $velocidad = isset($velocidades[$clave]) ? $velocidades[$clave] : 0.0;
            $cobertura = $cobertura_service->cobertura($sugerencia['stock_destino'], $velocidad);

            $sugerencias[$indice]['velocidad'] = $velocidad;
            $sugerencias[$indice]['cobertura'] = $cobertura;
            $sugerencias[$indice]['_orden'] = $indice;

            if (!is_null($cobertura) && $cobertura <= PurchaseSuggestionService::DIAS_PUNTO_PEDIDO) {
                $articulos_en_riesgo[(int) $sugerencia['article_id']] = true;
            }
        }

        // El orden de CoberturaService::asignar_prioridades: cobertura ascendente, nulos
        // al final, cantidad descendente; el índice de llegada desempata (usort no es estable).
        usort($sugerencias, function ($a, $b) {
            $a_nula = is_null($a['cobertura']);
            $b_nula = is_null($b['cobertura']);

            if ($a_nula !== $b_nula) {
                return $a_nula ? 1 : -1;
            }

            if (!$a_nula && $a['cobertura'] != $b['cobertura']) {
                return $a['cobertura'] <=> $b['cobertura'];
            }

            if ($a['suggested_amount'] != $b['suggested_amount']) {
                return $b['suggested_amount'] <=> $a['suggested_amount'];
            }

            return $a['_orden'] <=> $b['_orden'];
        });

        $top = array_slice($sugerencias, 0, self::TOPE_MOVIMIENTOS);

        $article_ids = array_column($top, 'article_id');
        $nombres_articulos = $this->nombres_de_articulos($article_ids);
        $stock_origen = $this->stock_en_origen($top);

        $lista = [];
        $prioridad = 1;

        foreach ($top as $sugerencia) {
            $article_id = (int) $sugerencia['article_id'];
            $desde_id = (int) $sugerencia['from_address_id'];
            $hacia_id = (int) $sugerencia['to_address_id'];

            $lista[] = [
                'article_id' => $article_id,
                'nombre'     => isset($nombres_articulos[$article_id]) ? $nombres_articulos[$article_id] : 'Artículo #' . $article_id,
                'desde'      => [
                    'address_id' => $desde_id,
                    'nombre'     => isset($nombres[$desde_id]) ? $nombres[$desde_id] : 'Sucursal #' . $desde_id,
                    'stock'      => isset($stock_origen[$article_id . '-' . $desde_id]) ? $stock_origen[$article_id . '-' . $desde_id] : null,
                ],
                'hacia'      => [
                    'address_id'       => $hacia_id,
                    'nombre'           => isset($nombres[$hacia_id]) ? $nombres[$hacia_id] : 'Sucursal #' . $hacia_id,
                    'stock'            => (float) $sugerencia['stock_destino'],
                    'velocidad_diaria' => round((float) $sugerencia['velocidad'], 2),
                    'cobertura_dias'   => is_null($sugerencia['cobertura']) ? null : round((float) $sugerencia['cobertura'], 1),
                ],
                'cantidad'   => (float) $sugerencia['suggested_amount'],
                'prioridad'  => $prioridad,
            ];

            $prioridad++;
        }

        return [
            'lista'               => $lista,
            'articulos_en_riesgo' => count($articulos_en_riesgo),
        ];
    }

    /**
     * Ids de los artículos activos del dueño con stock cargado en dos o más sucursales
     * y algún mínimo o máximo definido (sin eso el motor no tiene origen ni objetivo).
     *
     * @param User $owner
     * @return array
     */
    protected function articulos_candidatos(User $owner): array
    {
        $ids = DB::table('address_article')
            ->join('articles', 'articles.id', '=', 'address_article.article_id')
            ->join('addresses', 'addresses.id', '=', 'address_article.address_id')
            ->where('articles.user_id', $owner->id)
            ->where('addresses.user_id', $owner->id)
            ->whereNull('articles.deleted_at')
            ->whereNotNull('address_article.amount')
            ->groupBy('address_article.article_id')
            ->havingRaw('COUNT(DISTINCT address_article.address_id) >= 2')
            ->havingRaw('SUM(CASE WHEN address_article.stock_min IS NOT NULL OR address_article.stock_max IS NOT NULL THEN 1 ELSE 0 END) >= 1')
            ->orderBy('address_article.article_id')
            ->pluck('address_article.article_id')
            ->all();

        return array_map('intval', $ids);
    }

    /**
     * Stock actual en la sucursal de origen de cada movimiento (address_article.amount).
     *
     * @param array $sugerencias
     * @return array Mapa "article_id-address_id" => float
     */
    protected function stock_en_origen(array $sugerencias): array
    {
        $mapa = [];

        if (empty($sugerencias)) {
            return $mapa;
        }

        $filas = DB::table('address_article')
            ->whereIn('article_id', array_unique(array_column($sugerencias, 'article_id')))
            ->whereIn('address_id', array_unique(array_column($sugerencias, 'from_address_id')))
            ->get(['article_id', 'address_id', 'amount']);

        foreach ($filas as $fila) {
            $mapa[(int) $fila->article_id . '-' . (int) $fila->address_id] = (float) $fila->amount;
        }

        return $mapa;
    }

    /**
     * Artículos con stock y sin ventas reales en los últimos 90 días, los de mayor
     * valor a costo primero; y el valor a costo de TODOS los que están en esa
     * situación (no solo los 10 listados). Un artículo que nunca se vendió cuenta
     * los días desde que se cargó.
     *
     * @param User $owner
     * @param Carbon $fecha
     * @return array{lista: array, valor_inmovilizado: float}
     */
    protected function sin_rotacion(User $owner, Carbon $fecha): array
    {
        $desde = $fecha->copy()->subDays(self::DIAS_SIN_ROTACION)->startOfDay();

        // Valor a costo EN PESOS: stock × costo × cotización, donde la cotización es 1 para
        // un costo en pesos y, para un costo en dólares, el dólar del proveedor titular
        // (si lo tiene cargado y no es cero) o el de la cuenta (ver docblock de la clase).
        $dollar_cuenta = (float) $owner->dollar > 0 ? (float) $owner->dollar : null;

        $expresion_valor = 'articles.stock * COALESCE(articles.cost, 0) * '
            . '(CASE WHEN COALESCE(articles.cost_in_dollars, 0) = 1 THEN COALESCE(NULLIF(providers.dolar, 0), ?, 1) ELSE 1 END)';

        // Artículos con stock cuya última venta real es anterior a la ventana (o no existe).
        $base = DB::table('articles')
            ->leftJoin('providers', 'providers.id', '=', 'articles.provider_id')
            ->leftJoin('article_purchases', function ($join) use ($desde) {
                $join->on('article_purchases.article_id', '=', 'articles.id')
                    ->where('article_purchases.created_at', '>=', $desde);
            })
            ->leftJoin('sales', 'sales.id', '=', 'article_purchases.sale_id')
            ->where('articles.user_id', $owner->id)
            ->whereNull('articles.deleted_at')
            ->where('articles.status', 'active')
            ->where('articles.stock', '>', 0)
            ->groupBy('articles.id', 'articles.name', 'articles.stock', 'articles.cost', 'articles.cost_in_dollars', 'providers.dolar', 'articles.created_at')
            ->havingRaw('MAX(CASE WHEN sales.deleted_at IS NULL AND (sales.is_consolidacion_facturacion IS NULL OR sales.is_consolidacion_facturacion = 0) THEN article_purchases.created_at ELSE NULL END) IS NULL');

        $totales = DB::query()
            ->fromSub(
                (clone $base)->selectRaw('articles.id as id, ' . $expresion_valor . ' as valor', [$dollar_cuenta]),
                'sin_rotacion'
            )
            ->selectRaw('COUNT(*) as cantidad, COALESCE(SUM(valor), 0) as valor')
            ->first();

        $filas = (clone $base)
            ->selectRaw(
                'articles.id as id, articles.name as nombre, articles.stock as stock, articles.cost as cost, articles.created_at as creado, '
                . $expresion_valor . ' as valor',
                [$dollar_cuenta]
            )
            ->orderByDesc('valor')
            ->orderBy('articles.id')
            ->limit(self::TOPE_LISTA)
            ->get();

        if ($filas->isEmpty()) {
            return ['lista' => [], 'valor_inmovilizado' => (float) $this->monto($totales->valor)];
        }

        // Última venta real (de cualquier fecha) de los listados, para los días sin venta.
        $ultimas = $this->ventas_reales_desde_article_purchases(DB::table('article_purchases'), $owner->id)
            ->whereIn('article_purchases.article_id', $filas->pluck('id')->all())
            ->groupBy('article_purchases.article_id')
            ->selectRaw('article_purchases.article_id as article_id, MAX(article_purchases.created_at) as ultima')
            ->get();

        $ultima_por_articulo = [];

        foreach ($ultimas as $fila) {
            $ultima_por_articulo[(int) $fila->article_id] = $fila->ultima;
        }

        $hoy = $fecha->copy()->startOfDay();
        $lista = [];

        foreach ($filas as $fila) {
            $article_id = (int) $fila->id;

            $referencia = isset($ultima_por_articulo[$article_id])
                ? $ultima_por_articulo[$article_id]
                : $fila->creado;

            $lista[] = [
                'article_id'     => $article_id,
                'nombre'         => (string) $fila->nombre,
                'stock_total'    => (float) $fila->stock,
                'dias_sin_venta' => empty($referencia) ? null : Carbon::parse($referencia)->startOfDay()->diffInDays($hoy),
                'valor_a_costo'  => $this->monto($fila->valor),
            ];
        }

        return [
            'lista'              => $lista,
            'valor_inmovilizado' => (float) $this->monto($totales->valor),
        ];
    }
}
