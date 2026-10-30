<?php

namespace App\Services\Mostrador;

use App\Models\Address;
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
 *
 * DEPÓSITO MADRE (misión deposito-madre, 2/10/2026): si el comercio marcó uno, el motor
 * reparte desde el madre y el orden de los movimientos deja de ser solo urgencia: manda el
 * criterio del comercio (users.sugerencias_prioridad_destino) y la cobertura desempata, con
 * el MISMO comparador que la prioridad de las sugerencias guardadas
 * (CoberturaService::comparar_con_madre). Los hechos suman, siempre (solo agregados, nada se
 * renombra): `deposito_madre` y `criterio_prioridad` en la raíz (null sin madre: ahí el orden
 * es por urgencia, como siempre), `es_deposito_madre` y `facturacion_90_dias` en cada
 * sucursal, `desde.es_deposito_madre` y `hacia.facturacion_90_dias` en cada movimiento.
 *
 * Sucursales = addresses del dueño SIN buyer_id: la tabla guarda también los domicilios de los
 * compradores de la tienda (los escribe tienda-api, que comparte la base) con el user_id del
 * dueño, y sin ese corte contaban como sucursales (mismo hallazgo que ConsultasSistemaIaHelper).
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
            // Los domicilios de compradores de la tienda no son sucursales (ver docblock).
            ->whereNull('buyer_id')
            ->orderBy('id')
            ->get(['id', 'street', 'es_deposito_origen']);

        if ($sucursales->count() < 2) {
            return $this->no_aplica($fecha, 'El comercio tiene una sola sucursal: no hay traslados de stock que sugerir.');
        }

        $cobertura_service = new CoberturaService($owner->id);

        // Facturación de 90 días por sucursal: va en los hechos siempre (con o sin madre) y,
        // con madre y criterio 'ventas_sucursal', ordena los movimientos.
        $facturacion = $cobertura_service->facturacion_por_sucursal();

        // El madre se resuelve con la misma lectura que el motor (Address::deposito_madre_de):
        // el informe no puede creer que hay un madre y el motor que no.
        $madre = Address::deposito_madre_de($owner->id);
        $madre_id = is_null($madre) ? null : (int) $madre->id;
        $criterio = is_null($madre_id) ? null : $cobertura_service->criterio_prioridad_destino();

        $nombres = [];
        $lista_sucursales = [];

        foreach ($sucursales as $sucursal) {
            $address_id = (int) $sucursal->id;

            $nombres[$address_id] = (string) $sucursal->street;

            $lista_sucursales[] = [
                'address_id'          => $address_id,
                'nombre'              => (string) $sucursal->street,
                'es_deposito_origen'  => (bool) $sucursal->es_deposito_origen,
                'es_deposito_madre'   => $address_id === $madre_id,
                'facturacion_90_dias' => $this->facturacion_de($facturacion, $address_id),
            ];
        }

        $movimientos = $this->movimientos_sugeridos($owner, $nombres, $cobertura_service, $madre_id, $criterio, $facturacion);
        $sin_rotacion = $this->sin_rotacion($owner, $fecha);

        return [
            'aplica'                => true,
            'fecha'                 => $fecha->format('Y-m-d'),
            'sucursales'            => $lista_sucursales,
            'deposito_madre'        => is_null($madre_id) ? null : [
                'address_id' => $madre_id,
                'nombre'     => isset($nombres[$madre_id]) ? $nombres[$madre_id] : (string) $madre->street,
            ],
            'criterio_prioridad'    => $criterio,
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
        $sucursales = DB::table('addresses')->where('user_id', $owner->id)->whereNull('buyer_id')->count();

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
     * @param CoberturaService $cobertura_service Del dueño
     * @param int|null $madre_id Depósito madre, o null sin madre
     * @param string|null $criterio CoberturaService::PRIORIDAD_* con madre, null sin madre
     * @param array $facturacion Mapa address_id => facturación de 90 días en pesos
     * @return array{lista: array, articulos_en_riesgo: int}
     */
    protected function movimientos_sugeridos(User $owner, array $nombres, CoberturaService $cobertura_service, $madre_id, $criterio, array $facturacion): array
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

            if (!is_null($madre_id)) {
                // La forma que espera CoberturaService::comparar_con_madre, con la velocidad
                // redondeada como la guarda la sugerencia (velocidad_diaria, 4 decimales):
                // así el informe y la prioridad guardada comparan los mismos números.
                $sugerencias[$indice]['_comparable'] = [
                    'to_address_id' => (int) $sugerencia['to_address_id'],
                    'velocidad'     => round($velocidad, 4),
                    'cobertura'     => $cobertura,
                    'cantidad'      => (float) $sugerencia['suggested_amount'],
                    'desempate'     => $indice,
                ];
            }

            if (!is_null($cobertura) && $cobertura <= PurchaseSuggestionService::DIAS_PUNTO_PEDIDO) {
                $articulos_en_riesgo[(int) $sugerencia['article_id']] = true;
            }
        }

        if (!is_null($madre_id)) {
            // Con madre: el orden de CoberturaService::asignar_prioridades con madre, con el
            // MISMO comparador (criterio del comercio primero, la urgencia desempata). El
            // índice de llegada desempata al final, como sin madre.
            usort($sugerencias, function ($a, $b) use ($cobertura_service, $criterio, $facturacion) {
                return $cobertura_service->comparar_con_madre($a['_comparable'], $b['_comparable'], $criterio, $facturacion);
            });
        } else {
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
        }

        $top = array_slice($sugerencias, 0, self::TOPE_MOVIMIENTOS);

        $article_ids = array_column($top, 'article_id');
        $nombres_articulos = $this->nombres_de_articulos($article_ids);
        $imagenes = $this->imagenes_de($article_ids);
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
                    'address_id'        => $desde_id,
                    'nombre'            => isset($nombres[$desde_id]) ? $nombres[$desde_id] : 'Sucursal #' . $desde_id,
                    'stock'             => isset($stock_origen[$article_id . '-' . $desde_id]) ? $stock_origen[$article_id . '-' . $desde_id] : null,
                    'es_deposito_madre' => $desde_id === $madre_id,
                ],
                'hacia'      => [
                    'address_id'          => $hacia_id,
                    'nombre'              => isset($nombres[$hacia_id]) ? $nombres[$hacia_id] : 'Sucursal #' . $hacia_id,
                    'stock'               => (float) $sugerencia['stock_destino'],
                    'velocidad_diaria'    => round((float) $sugerencia['velocidad'], 2),
                    'cobertura_dias'      => is_null($sugerencia['cobertura']) ? null : round((float) $sugerencia['cobertura'], 1),
                    'facturacion_90_dias' => $this->facturacion_de($facturacion, $hacia_id),
                ],
                'cantidad'   => (float) $sugerencia['suggested_amount'],
                'prioridad'  => $prioridad,
                'imagen_url' => isset($imagenes[$article_id]) ? $imagenes[$article_id] : null,
            ];

            $prioridad++;
        }

        return [
            'lista'               => $lista,
            'articulos_en_riesgo' => count($articulos_en_riesgo),
        ];
    }

    /**
     * Facturación de 90 días en pesos de una sucursal (0.0 si no vendió nada en la ventana).
     *
     * @param array $facturacion Mapa de CoberturaService::facturacion_por_sucursal()
     * @param int $address_id
     * @return float
     */
    protected function facturacion_de(array $facturacion, $address_id)
    {
        return isset($facturacion[$address_id]) ? (float) $this->monto($facturacion[$address_id]) : 0.0;
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
     * Es lo que usa el informe del Mostrador, con sus defaults de siempre (90 días, 10 artículos,
     * por valor a costo): desde la misión agente-mejoras-uso-real (E2, 29/10/2026) la consulta vive
     * en sin_rotacion_con_opciones(), que la herramienta del agente llama con otros parámetros. Con
     * las opciones vacías la consulta y la respuesta son exactamente las de antes.
     *
     * @param User $owner
     * @param Carbon $fecha
     * @return array{lista: array, valor_inmovilizado: float}
     */
    protected function sin_rotacion(User $owner, Carbon $fecha): array
    {
        return $this->sin_rotacion_con_opciones($owner, $fecha, []);
    }

    /**
     * Los artículos SIN ROTACIÓN con parámetros (misión agente-mejoras-uso-real, E2 punto 5,
     * 29/10/2026). Hasta acá solo lo usaba el Mostrador, con la ventana y el tope fijos; Pablo (Ferre
     * Total, CONV 11) pidió «el top ten con menos rotación» y el agente armó un ranking a mano con
     * resumir_datos sobre los renglones de venta: devolvió seis nombres diciendo que eran cuatro,
     * reescribió los nombres (y después no encontró sus fichas) y no supo decir si tenían stock ni
     * si alguna vez se habían comprado. La herramienta consultar_articulos_sin_rotacion
     * (ArticulosSinRotacionIaHelper) llama esto.
     *
     * Opciones (todas opcionales; sin ninguna, lo del Mostrador tal cual):
     *  - `dias` (int): la ventana sin ventas. Default DIAS_SIN_ROTACION (90).
     *  - `limite` (int): cuántos se listan. Default TOPE_LISTA (10).
     *  - `orden`: 'valor' (default: mayor valor a costo primero), 'dias' (los que llevan más días sin
     *    venderse primero, contando desde la última venta de cualquier fecha o, si nunca se vendió,
     *    desde el alta) o 'stock' (más stock primero).
     *  - `solo_comprados` (bool): solo los que tienen alguna compra a proveedor cargada.
     *  - `con_detalle` (bool): suma a cada fila `ultima_venta`, `nunca_se_vendio`, `ultima_compra` y
     *    `compras` (dos consultas más, sobre los listados). Sin él, las claves del Mostrador.
     *  - `con_imagenes` (bool, default true): la `imagen_url` de cada fila, que el Mostrador muestra.
     *
     * Devuelve además `cantidad`: cuántos están sin rotación en total (no solo los listados).
     *
     * @param User   $owner
     * @param Carbon $fecha
     * @param array  $opciones
     * @return array{lista: array, valor_inmovilizado: float, cantidad: int}
     */
    public function sin_rotacion_con_opciones(User $owner, Carbon $fecha, array $opciones): array
    {
        $dias = isset($opciones['dias']) && (int) $opciones['dias'] > 0 ? (int) $opciones['dias'] : self::DIAS_SIN_ROTACION;
        $limite = isset($opciones['limite']) && (int) $opciones['limite'] > 0 ? (int) $opciones['limite'] : self::TOPE_LISTA;
        $orden = isset($opciones['orden']) && in_array($opciones['orden'], ['valor', 'dias', 'stock'], true) ? $opciones['orden'] : 'valor';
        $solo_comprados = !empty($opciones['solo_comprados']);
        $con_detalle = !empty($opciones['con_detalle']);
        $con_imagenes = !array_key_exists('con_imagenes', $opciones) || !empty($opciones['con_imagenes']);

        $desde = $fecha->copy()->subDays($dias)->startOfDay();

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

        // "¿Pero son productos que alguna vez compré?" (CONV 11): solo los que tienen una compra.
        if ($solo_comprados) {
            $base->whereExists(function ($q) use ($owner) {
                $q->selectRaw('1')
                    ->from('article_provider_order')
                    ->join('provider_orders', 'provider_orders.id', '=', 'article_provider_order.provider_order_id')
                    ->whereColumn('article_provider_order.article_id', 'articles.id')
                    ->where('provider_orders.user_id', $owner->id);
            });
        }

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
            );

        /*
         * El orden. 'valor' es el de siempre (el del Mostrador). 'dias' necesita la última venta real
         * de CUALQUIER fecha (la del join es solo de la ventana, y ahí no hay ninguna): va como
         * subconsulta correlacionada, con el alta del artículo cuando nunca se vendió, de la más vieja
         * a la más nueva. 'stock', más stock primero.
         */
        if ($orden === 'dias') {
            $filas->orderByRaw(
                'COALESCE((SELECT MAX(ap_orden.created_at) FROM article_purchases ap_orden'
                . ' JOIN sales s_orden ON s_orden.id = ap_orden.sale_id'
                . ' WHERE ap_orden.article_id = articles.id AND s_orden.deleted_at IS NULL'
                . ' AND (s_orden.is_consolidacion_facturacion IS NULL OR s_orden.is_consolidacion_facturacion = 0)), articles.created_at) ASC'
            );
        } elseif ($orden === 'stock') {
            $filas->orderByDesc('articles.stock');
        } else {
            $filas->orderByDesc('valor');
        }

        $filas = $filas->orderBy('articles.id')
            ->limit($limite)
            ->get();

        if ($filas->isEmpty()) {
            return [
                'lista'              => [],
                'valor_inmovilizado' => (float) $this->monto($totales->valor),
                'cantidad'           => (int) $totales->cantidad,
            ];
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
        $imagenes = $con_imagenes ? $this->imagenes_de($filas->pluck('id')->all()) : [];
        $compras = $con_detalle ? $this->compras_de_los_articulos($owner, $filas->pluck('id')->all()) : [];
        $lista = [];

        foreach ($filas as $fila) {
            $article_id = (int) $fila->id;

            $referencia = isset($ultima_por_articulo[$article_id])
                ? $ultima_por_articulo[$article_id]
                : $fila->creado;

            $renglon = [
                'article_id'     => $article_id,
                'nombre'         => (string) $fila->nombre,
                'stock_total'    => (float) $fila->stock,
                'dias_sin_venta' => empty($referencia) ? null : Carbon::parse($referencia)->startOfDay()->diffInDays($hoy),
                'valor_a_costo'  => $this->monto($fila->valor),
            ];

            // La foto, solo para quien la muestra (el Mostrador): el agente no la necesita.
            if ($con_imagenes) {
                $renglon['imagen_url'] = isset($imagenes[$article_id]) ? $imagenes[$article_id] : null;
            }

            // El detalle que pide la herramienta del agente: cuándo se vendió y cuándo se compró.
            if ($con_detalle) {
                $renglon['ultima_venta'] = isset($ultima_por_articulo[$article_id]) ? $this->fecha_ymd($ultima_por_articulo[$article_id]) : null;
                $renglon['nunca_se_vendio'] = !isset($ultima_por_articulo[$article_id]);
                $renglon['ultima_compra'] = isset($compras[$article_id]) ? $compras[$article_id]['ultima'] : null;
                $renglon['compras'] = isset($compras[$article_id]) ? $compras[$article_id]['cantidad'] : 0;
            }

            $lista[] = $renglon;
        }

        return [
            'lista'              => $lista,
            'valor_inmovilizado' => (float) $this->monto($totales->valor),
            'cantidad'           => (int) $totales->cantidad,
        ];
    }

    /**
     * Los artículos CON stock que SÍ se vendieron en la ventana, de los que menos unidades vendieron
     * a los que más (misión agente-mejoras-uso-real, E2 punto 5). Es el complemento de
     * sin_rotacion_con_opciones() para "los de menos rotación" cuando los que no se vendieron nada no
     * llegan al tope pedido. Mismo criterio de "venta real" (sin borradas ni contenedoras de una
     * consolidación AFIP), mismos artículos (activos, no borrados, stock > 0) y la misma opción
     * `solo_comprados`.
     *
     * Opciones: `dias` (default DIAS_SIN_ROTACION), `limite` (default TOPE_LISTA), `solo_comprados`,
     * `excluir` (ids que ya se listaron).
     *
     * @param User   $owner
     * @param Carbon $fecha
     * @param array  $opciones
     * @return array<int, array<string, mixed>>  {article_id, nombre, stock_total, unidades_vendidas,
     *         ultima_venta, ultima_compra, compras}
     */
    public function menos_vendidos_con_stock(User $owner, Carbon $fecha, array $opciones): array
    {
        $dias = isset($opciones['dias']) && (int) $opciones['dias'] > 0 ? (int) $opciones['dias'] : self::DIAS_SIN_ROTACION;
        $limite = isset($opciones['limite']) && (int) $opciones['limite'] > 0 ? (int) $opciones['limite'] : self::TOPE_LISTA;
        $excluir = isset($opciones['excluir']) && is_array($opciones['excluir']) ? array_values(array_map('intval', $opciones['excluir'])) : [];

        $desde = $fecha->copy()->subDays($dias)->startOfDay();

        // Las ventas reales de la ventana, por artículo: el join de siempre (RecolectorBase).
        $consulta = $this->ventas_reales_desde_article_purchases(DB::table('article_purchases'), $owner->id)
            ->whereNull('articles.deleted_at')
            ->where('articles.status', 'active')
            ->where('articles.stock', '>', 0)
            ->where('article_purchases.created_at', '>=', $desde);

        if (!empty($excluir)) {
            $consulta->whereNotIn('articles.id', $excluir);
        }

        if (!empty($opciones['solo_comprados'])) {
            $consulta->whereExists(function ($q) use ($owner) {
                $q->selectRaw('1')
                    ->from('article_provider_order')
                    ->join('provider_orders', 'provider_orders.id', '=', 'article_provider_order.provider_order_id')
                    ->whereColumn('article_provider_order.article_id', 'articles.id')
                    ->where('provider_orders.user_id', $owner->id);
            });
        }

        $filas = $consulta
            ->groupBy('articles.id', 'articles.name', 'articles.stock')
            ->selectRaw('articles.id as id, articles.name as nombre, articles.stock as stock, COALESCE(SUM(article_purchases.amount), 0) as unidades, MAX(article_purchases.created_at) as ultima')
            ->orderBy('unidades')
            ->orderBy('articles.id')
            ->limit($limite)
            ->get();

        $compras = $this->compras_de_los_articulos($owner, $filas->pluck('id')->all());

        $lista = [];

        foreach ($filas as $fila) {
            $article_id = (int) $fila->id;

            $lista[] = [
                'article_id'        => $article_id,
                'nombre'            => (string) $fila->nombre,
                'stock_total'       => (float) $fila->stock,
                'unidades_vendidas' => round((float) $fila->unidades, 2),
                'ultima_venta'      => $this->fecha_ymd($fila->ultima),
                'ultima_compra'     => isset($compras[$article_id]) ? $compras[$article_id]['ultima'] : null,
                'compras'           => isset($compras[$article_id]) ? $compras[$article_id]['cantidad'] : 0,
            ];
        }

        return $lista;
    }

    /**
     * Cuántas compras a proveedor tiene cada artículo del lote y la fecha de la última, en UNA
     * consulta. Las compras son `provider_orders` del dueño (no `article_purchases`, que es lo que le
     * compró un cliente al comercio).
     *
     * @param User  $owner
     * @param array $article_ids
     * @return array<int, array{cantidad: int, ultima: string|null}>
     */
    protected function compras_de_los_articulos(User $owner, array $article_ids): array
    {
        $article_ids = array_values(array_unique(array_filter(array_map('intval', $article_ids))));

        if (empty($article_ids)) {
            return [];
        }

        $filas = DB::table('article_provider_order')
            ->join('provider_orders', 'provider_orders.id', '=', 'article_provider_order.provider_order_id')
            ->where('provider_orders.user_id', $owner->id)
            ->whereIn('article_provider_order.article_id', $article_ids)
            ->groupBy('article_provider_order.article_id')
            ->selectRaw('article_provider_order.article_id as article_id, COUNT(DISTINCT provider_orders.id) as cantidad, MAX(provider_orders.created_at) as ultima')
            ->get();

        $mapa = [];

        foreach ($filas as $fila) {
            $mapa[(int) $fila->article_id] = [
                'cantidad' => (int) $fila->cantidad,
                'ultima'   => $this->fecha_ymd($fila->ultima),
            ];
        }

        return $mapa;
    }
}
