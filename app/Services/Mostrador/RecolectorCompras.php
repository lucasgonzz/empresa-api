<?php

namespace App\Services\Mostrador;

use App\Models\PurchaseSuggestion;
use App\Models\User;
use App\Services\PurchaseSuggestion\ContextoFinancieroService;
use App\Services\StockSuggestion\CoberturaService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Hechos del informe "Compras" (tipo 'compras'): qué conviene reponer, agrupado por
 * el proveedor titular de cada artículo, con la deuda con ese proveedor, su última
 * compra, el último costo conocido y el mejor precio de otro proveedor; los artículos
 * a reponer sin proveedor asignado; el contexto financiero y la demanda sin stock.
 *
 * El cálculo de QUÉ reponer y CUÁNTO es el del motor de compras
 * (PurchaseSuggestionService, vía CalculoDeComprasMostrador): cobertura por debajo
 * de los 15 días de punto de pedido, o stock por debajo del mínimo cargado; cantidad
 * para cubrir 30 días más 7 de demora. Se corre en memoria sobre una cabecera SIN
 * guardar: no se crea ninguna purchase_suggestion.
 *
 * Solo entran a ese cálculo los artículos que PUEDEN disparar (los que tienen mínimo
 * cargado o alguna venta en la ventana de velocidad de 455 días): los demás dan
 * cobertura infinita y sin mínimo, o sea que el motor los descartaría igual. Es un
 * recorte de trabajo, no de resultado.
 *
 * `demanda_sin_stock`: artículos en cero que la gente sigue abriendo en la tienda
 * (product_view de los últimos 7 días). `consultas_whatsapp_7d` viaja null: el sistema
 * no registra a qué artículo se refiere cada consulta de WhatsApp, y un conteo por
 * coincidencia de nombre sería un número inventado.
 */
class RecolectorCompras extends RecolectorBase
{
    /** Tope de artículos por proveedor. */
    const TOPE_ARTICULOS_POR_PROVEEDOR = 20;

    /** Tamaño de lote de artículos que se le pasa al motor por vez. */
    const LOTE_CALCULO = 1000;

    /** Ventana de la demanda sin stock, en días. */
    const DIAS_DEMANDA = 7;

    /**
     * @param User $owner
     * @param Carbon $fecha El día del informe (hoy, por defecto)
     * @return array
     */
    public function recolectar(User $owner, Carbon $fecha): array
    {
        $lineas = $this->lineas_del_motor($owner);

        $por_titular = [];
        $sin_proveedor = [];

        foreach ($lineas as $linea) {
            $titular = $linea['provider_id_titular'];

            if (is_null($titular)) {
                $sin_proveedor[] = $linea;
                continue;
            }

            $por_titular[(int) $titular][] = $linea;
        }

        $provider_ids = array_keys($por_titular);

        // Deuda por proveedor y disponible en cajas, con el servicio del módulo de compras
        // (una query a credit_accounts para todos los proveedores + N cajas).
        $contexto = ContextoFinancieroService::armar($owner->id, $provider_ids, []);

        $deuda_por_proveedor = [];
        $nombre_por_proveedor = [];

        foreach ($contexto['proveedores'] as $proveedor) {
            $deuda_por_proveedor[(int) $proveedor['provider_id']] = (float) $proveedor['deuda_pesos'];
            $nombre_por_proveedor[(int) $proveedor['provider_id']] = $proveedor['nombre'];
        }

        return [
            'aplica'        => true,
            'fecha'         => $fecha->format('Y-m-d'),
            'por_proveedor' => $this->por_proveedor($owner, $por_titular, $deuda_por_proveedor, $nombre_por_proveedor),
            'sin_proveedor' => $this->sin_proveedor($sin_proveedor),
            'contexto_financiero' => [
                'saldo_cajas'             => $this->monto($contexto['caja_disponible_pesos']),
                'deuda_total_proveedores' => $this->deuda_total($owner, 'provider'),
                'deuda_clientes'          => $this->deuda_total($owner, 'client'),
            ],
            'demanda_sin_stock' => $this->demanda_sin_stock($owner),
        ];
    }

    /**
     * Corre el motor de compras en memoria sobre los artículos que pueden disparar y
     * devuelve sus líneas (ver PurchaseSuggestionService::calcular_para_articulos).
     *
     * @param User $owner
     * @return array
     */
    protected function lineas_del_motor(User $owner): array
    {
        $candidatos = $this->articulos_candidatos($owner);

        if (empty($candidatos)) {
            return [];
        }

        // Cabecera SIN guardar: user_id y los cuatro parámetros en su default (null → constantes).
        $cabecera = new PurchaseSuggestion(['user_id' => $owner->id]);
        $motor = new CalculoDeComprasMostrador($cabecera);

        $lineas = [];

        foreach (array_chunk($candidatos, self::LOTE_CALCULO) as $lote) {
            foreach ($motor->calcular_para_articulos($lote) as $linea) {
                $lineas[] = $linea;
            }
        }

        return $lineas;
    }

    /**
     * Ids de los artículos activos del dueño que pueden disparar una reposición: con
     * mínimo cargado (en algún depósito o en el artículo) o con alguna venta en la
     * ventana de velocidad del motor (reciente + interanual).
     *
     * @param User $owner
     * @return array
     */
    protected function articulos_candidatos(User $owner): array
    {
        $desde = now()->subDays(CoberturaService::DESFASE_INTERANUAL_DIAS + CoberturaService::VENTANA_DIAS);

        $con_minimo_en_deposito = DB::table('address_article')
            ->join('articles', 'articles.id', '=', 'address_article.article_id')
            ->where('articles.user_id', $owner->id)
            ->whereNull('articles.deleted_at')
            ->whereNotNull('address_article.stock_min')
            ->distinct()
            ->pluck('address_article.article_id')
            ->all();

        $con_minimo_propio = DB::table('articles')
            ->where('user_id', $owner->id)
            ->whereNull('deleted_at')
            ->where('stock_min', '>', 0)
            ->pluck('id')
            ->all();

        $con_ventas = DB::table('article_purchases')
            ->join('articles', 'articles.id', '=', 'article_purchases.article_id')
            ->where('articles.user_id', $owner->id)
            ->whereNull('articles.deleted_at')
            ->where('article_purchases.created_at', '>=', $desde)
            ->distinct()
            ->pluck('article_purchases.article_id')
            ->all();

        $ids = array_map('intval', array_merge($con_minimo_en_deposito, $con_minimo_propio, $con_ventas));

        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }

    /**
     * Bloque `por_proveedor`: hasta 10 proveedores titulares (los que más artículos
     * tienen para reponer primero), cada uno con hasta 20 artículos ordenados por
     * urgencia (cobertura ascendente, nulos al final, cantidad descendente).
     *
     * @param User $owner
     * @param array $por_titular Mapa provider_id => líneas
     * @param array $deuda_por_proveedor
     * @param array $nombre_por_proveedor
     * @return array
     */
    protected function por_proveedor(User $owner, array $por_titular, array $deuda_por_proveedor, array $nombre_por_proveedor): array
    {
        if (empty($por_titular)) {
            return [];
        }

        // Orden de proveedores: más artículos primero, después más total estimado, después id.
        $orden = [];

        foreach ($por_titular as $provider_id => $lineas) {
            $orden[] = [
                'provider_id' => $provider_id,
                'cantidad'    => count($lineas),
                'total'       => $this->total_estimado($lineas),
            ];
        }

        usort($orden, function ($a, $b) {
            if ($a['cantidad'] !== $b['cantidad']) {
                return $b['cantidad'] <=> $a['cantidad'];
            }

            if ($a['total'] != $b['total']) {
                return $b['total'] <=> $a['total'];
            }

            return $a['provider_id'] <=> $b['provider_id'];
        });

        $orden = array_slice($orden, 0, self::TOPE_LISTA);

        $provider_ids = array_column($orden, 'provider_id');
        $ultimas_compras = $this->ultimas_compras($owner, $provider_ids);

        // Ids de artículos y de "otros proveedores" que van a aparecer, para resolver
        // nombres, imágenes y fechas de último costo en una consulta por cosa.
        $lineas_a_mostrar = [];

        foreach ($orden as $item) {
            $lineas = $this->ordenar_lineas($por_titular[$item['provider_id']]);
            $lineas_a_mostrar[$item['provider_id']] = array_slice($lineas, 0, self::TOPE_ARTICULOS_POR_PROVEEDOR);
        }

        $article_ids = [];
        $otros_provider_ids = [];
        $pares_titular = [];

        foreach ($lineas_a_mostrar as $provider_id => $lineas) {
            foreach ($lineas as $linea) {
                $article_ids[] = (int) $linea['article_id'];
                $pares_titular[] = [(int) $linea['article_id'], (int) $provider_id];

                if (!is_null($linea['provider_id']) && (int) $linea['provider_id'] !== (int) $provider_id) {
                    $otros_provider_ids[] = (int) $linea['provider_id'];
                }
            }
        }

        $nombres_articulos = $this->nombres_de_articulos($article_ids);
        $nombres_otros = $this->nombres_de_proveedores(array_merge($otros_provider_ids, $provider_ids));
        $fechas_ultimo_costo = $this->fechas_de_ultimo_costo($owner, $pares_titular);

        $resultado = [];

        foreach ($orden as $item) {
            $provider_id = (int) $item['provider_id'];
            $articulos = [];

            foreach ($lineas_a_mostrar[$provider_id] as $linea) {
                $article_id = (int) $linea['article_id'];

                $mejor_precio = null;

                if (!is_null($linea['provider_id']) && (int) $linea['provider_id'] !== $provider_id) {
                    $otro_id = (int) $linea['provider_id'];

                    $mejor_precio = [
                        'provider_id' => $otro_id,
                        'nombre'      => isset($nombres_otros[$otro_id]) ? $nombres_otros[$otro_id] : 'Proveedor #' . $otro_id,
                        'costo'       => $this->monto($linea['costo_estimado']),
                    ];
                }

                $clave_par = $article_id . '-' . $provider_id;

                $articulos[] = [
                    'article_id'         => $article_id,
                    'nombre'             => isset($nombres_articulos[$article_id]) ? $nombres_articulos[$article_id] : 'Artículo #' . $article_id,
                    'stock'              => (float) $linea['stock_global'],
                    'velocidad_diaria'   => round((float) $linea['velocidad_diaria'], 2),
                    'cobertura_dias'     => is_null($linea['cobertura_dias']) ? null : round((float) $linea['cobertura_dias'], 1),
                    'cantidad_sugerida'  => (float) $linea['cantidad_sugerida'],
                    'ultimo_costo'       => $this->monto($linea['costo_proveedor_titular']),
                    'ultimo_costo_fecha' => isset($fechas_ultimo_costo[$clave_par]) ? $fechas_ultimo_costo[$clave_par] : null,
                    'mejor_precio_otro_proveedor' => $mejor_precio,
                ];
            }

            $nombre = isset($nombre_por_proveedor[$provider_id]) && !is_null($nombre_por_proveedor[$provider_id])
                ? $nombre_por_proveedor[$provider_id]
                : (isset($nombres_otros[$provider_id]) ? $nombres_otros[$provider_id] : 'Proveedor #' . $provider_id);

            $resultado[] = [
                'provider_id'         => $provider_id,
                'nombre'              => (string) $nombre,
                'deuda_con_proveedor' => $this->monto(isset($deuda_por_proveedor[$provider_id]) ? $deuda_por_proveedor[$provider_id] : 0.0),
                'ultima_compra'       => isset($ultimas_compras[$provider_id]) ? $ultimas_compras[$provider_id] : null,
                'articulos'           => $articulos,
                'total_estimado'      => $this->monto($item['total']),
            ];
        }

        return $resultado;
    }

    /**
     * Total estimado de un proveedor: cantidad sugerida por último costo del titular,
     * sobre TODAS sus líneas (no solo las 20 que se listan); las líneas sin costo no
     * aportan monto.
     *
     * @param array $lineas
     * @return float
     */
    protected function total_estimado(array $lineas): float
    {
        $total = 0.0;

        foreach ($lineas as $linea) {
            if (!is_null($linea['costo_proveedor_titular'])) {
                $total += (float) $linea['costo_proveedor_titular'] * (float) $linea['cantidad_sugerida'];
            }
        }

        return $total;
    }

    /**
     * Orden de urgencia del motor (PrioridadComprasService): cobertura ascendente con
     * los nulos (cobertura infinita) al final, después cantidad descendente, después id.
     *
     * @param array $lineas
     * @return array
     */
    protected function ordenar_lineas(array $lineas): array
    {
        usort($lineas, function ($a, $b) {
            $a_nula = is_null($a['cobertura_dias']);
            $b_nula = is_null($b['cobertura_dias']);

            if ($a_nula !== $b_nula) {
                return $a_nula ? 1 : -1;
            }

            if (!$a_nula && $a['cobertura_dias'] != $b['cobertura_dias']) {
                return $a['cobertura_dias'] <=> $b['cobertura_dias'];
            }

            if ($a['cantidad_sugerida'] != $b['cantidad_sugerida']) {
                return $b['cantidad_sugerida'] <=> $a['cantidad_sugerida'];
            }

            return $a['article_id'] <=> $b['article_id'];
        });

        return $lineas;
    }

    /**
     * Bloque `sin_proveedor`: lo que hay que reponer y no tiene proveedor titular, lo
     * más urgente primero.
     *
     * @param array $lineas
     * @return array
     */
    protected function sin_proveedor(array $lineas): array
    {
        if (empty($lineas)) {
            return [];
        }

        $lineas = array_slice($this->ordenar_lineas($lineas), 0, self::TOPE_LISTA);
        $nombres = $this->nombres_de_articulos(array_column($lineas, 'article_id'));

        $lista = [];

        foreach ($lineas as $linea) {
            $article_id = (int) $linea['article_id'];

            $lista[] = [
                'article_id'     => $article_id,
                'nombre'         => isset($nombres[$article_id]) ? $nombres[$article_id] : 'Artículo #' . $article_id,
                'stock'          => (float) $linea['stock_global'],
                'cobertura_dias' => is_null($linea['cobertura_dias']) ? null : round((float) $linea['cobertura_dias'], 1),
            ];
        }

        return $lista;
    }

    /**
     * Última orden de compra de cada proveedor (fecha y total), una consulta.
     *
     * @param User $owner
     * @param array $provider_ids
     * @return array Mapa provider_id => ['fecha' => 'Y-m-d', 'total' => float]
     */
    protected function ultimas_compras(User $owner, array $provider_ids): array
    {
        $mapa = [];

        if (empty($provider_ids)) {
            return $mapa;
        }

        $ultimas = DB::table('provider_orders')
            ->where('user_id', $owner->id)
            ->whereIn('provider_id', $provider_ids)
            ->groupBy('provider_id')
            ->selectRaw('provider_id, MAX(id) as ultimo_id')
            ->get();

        if ($ultimas->isEmpty()) {
            return $mapa;
        }

        $ordenes = DB::table('provider_orders')
            ->whereIn('id', $ultimas->pluck('ultimo_id')->all())
            ->get(['provider_id', 'created_at', 'total']);

        foreach ($ordenes as $orden) {
            $mapa[(int) $orden->provider_id] = [
                'fecha' => $this->fecha_ymd($orden->created_at),
                'total' => $this->monto($orden->total),
            ];
        }

        return $mapa;
    }

    /**
     * Fecha de la última oferta registrada de cada par (artículo, proveedor titular),
     * sin ventana: es "cuándo fue la última vez que se supo ese costo".
     *
     * @param User $owner
     * @param array $pares [[article_id, provider_id], ...]
     * @return array Mapa "article_id-provider_id" => 'Y-m-d'
     */
    protected function fechas_de_ultimo_costo(User $owner, array $pares): array
    {
        $mapa = [];

        if (empty($pares)) {
            return $mapa;
        }

        $article_ids = array_values(array_unique(array_column($pares, 0)));
        $provider_ids = array_values(array_unique(array_column($pares, 1)));

        $filas = DB::table('provider_price_offers')
            ->where('user_id', $owner->id)
            ->whereIn('article_id', $article_ids)
            ->whereIn('provider_id', $provider_ids)
            ->groupBy('article_id', 'provider_id')
            ->selectRaw('article_id, provider_id, MAX(fecha) as fecha')
            ->get();

        foreach ($filas as $fila) {
            $mapa[(int) $fila->article_id . '-' . (int) $fila->provider_id] = $this->fecha_ymd($fila->fecha);
        }

        return $mapa;
    }

    /**
     * Nombres de un lote de proveedores (con borrados: un proveedor borrado sigue
     * siendo el titular de sus artículos hasta que se los reasignen).
     *
     * @param array $provider_ids
     * @return array Mapa provider_id => nombre
     */
    protected function nombres_de_proveedores(array $provider_ids): array
    {
        $mapa = [];

        $provider_ids = array_values(array_unique(array_filter(array_map('intval', $provider_ids))));

        if (empty($provider_ids)) {
            return $mapa;
        }

        $filas = DB::table('providers')
            ->whereIn('id', $provider_ids)
            ->get(['id', 'name']);

        foreach ($filas as $fila) {
            $mapa[(int) $fila->id] = (string) $fila->name;
        }

        return $mapa;
    }

    /**
     * Deuda total en pesos con los proveedores o de los clientes (credit_accounts,
     * la fuente de verdad; saldo positivo = deuda).
     *
     * @param User $owner
     * @param string $model_name 'provider' | 'client'
     * @return float
     */
    protected function deuda_total(User $owner, string $model_name): float
    {
        $total = DB::table('credit_accounts')
            ->where('user_id', $owner->id)
            ->where('model_name', $model_name)
            ->where(function ($q) {
                $q->whereNull('moneda_id')->orWhere('moneda_id', self::MONEDA_PESOS);
            })
            ->sum('saldo');

        return (float) $this->monto($total);
    }

    /**
     * Artículos en cero que la gente abrió en la tienda en los últimos 7 días, los más
     * mirados primero. Sin tienda (sin eventos) la lista queda vacía.
     *
     * @param User $owner
     * @return array
     */
    protected function demanda_sin_stock(User $owner): array
    {
        // En cero = stock cargado y <= 0; con stock null el artículo no controla stock y la
        // tienda lo vende como disponible (RecolectorBase::en_cero).
        $filas = $this->en_cero(DB::table('buyer_tracking_events as e'), 'a.stock')
            ->join('articles as a', 'a.id', '=', 'e.article_id')
            ->where('e.user_id', $owner->id)
            ->where('e.event_type', 'product_view')
            ->where('e.occurred_at', '>=', now()->subDays(self::DIAS_DEMANDA)->startOfDay())
            ->whereNull('a.deleted_at')
            ->groupBy('e.article_id', 'a.name')
            ->selectRaw('e.article_id as article_id, a.name as nombre, COUNT(*) as vistas')
            ->orderByDesc('vistas')
            ->orderBy('e.article_id')
            ->limit(self::TOPE_LISTA)
            ->get();

        $lista = [];

        foreach ($filas as $fila) {
            $lista[] = [
                'article_id'           => (int) $fila->article_id,
                'nombre'               => (string) $fila->nombre,
                'busquedas_tienda_7d'  => (int) $fila->vistas,
                'consultas_whatsapp_7d' => null,
            ];
        }

        return $lista;
    }
}
