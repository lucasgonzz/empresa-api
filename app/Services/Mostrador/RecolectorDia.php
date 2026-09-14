<?php

namespace App\Services\Mostrador;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Hechos del informe "Rendimiento de ayer" (tipo 'dia'): ventas, comparación con el
 * mismo día de la semana pasada y con el promedio de 30 días, artículos (más vendidos,
 * los que volvieron a venderse, los que quedaron sin stock), cobranzas, compras y
 * gastos, caja, tienda y resultado.
 *
 * Todo sale de sales / article_purchases / current_acounts / credit_accounts /
 * provider_orders / expenses / movimiento_cajas / orders, siempre por el user_id del
 * dueño y en PESOS: las ventas, compras, gastos y notas de crédito en dólares
 * (moneda_id = 2) quedan afuera de los totales, igual que en el reporte de
 * rendimiento del sistema (PerformanceHelper separa las dos monedas y acá solo viaja
 * la de pesos).
 *
 * `resultado` sale de company_performances cuando existe la fila de ese día
 * (cualquier from_today); si no, se calcula sobre las mismas ventas con la fórmula de
 * PerformanceHelper::set_company_performance_props(): ingresos_netos = vendido -
 * devoluciones - costo de lo vendido, rentabilidad = ingresos_netos - gastos. Las dos
 * son MONTOS en pesos, no porcentajes (misma semántica que las columnas homónimas).
 */
class RecolectorDia extends RecolectorBase
{
    /** Días sin venderse a partir de los cuales un artículo "volvió a venderse". */
    const DIAS_VOLVIO_A_VENDERSE = 60;

    /** Ventana del promedio diario de comparación. */
    const DIAS_PROMEDIO = 30;

    /** Id del método de pago "Efectivo", el default histórico de una venta sin método cargado. */
    const METODO_PAGO_DEFAULT_ID = 3;

    /** Nombres de los días de la semana, por Carbon::dayOfWeek (0 = domingo). */
    const DIAS_SEMANA = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];

    /**
     * @param User $owner
     * @param Carbon $fecha El día del que habla el informe (ayer, por defecto)
     * @return array
     */
    public function recolectar(User $owner, Carbon $fecha): array
    {
        $inicio = $fecha->copy()->startOfDay();
        $fin    = $fecha->copy()->endOfDay();

        $ventas = $this->ventas($owner, $inicio, $fin);
        $gastos = $this->gastos($owner, $inicio, $fin);

        return [
            'aplica'          => true,
            'fecha'           => $fecha->format('Y-m-d'),
            'dia_semana'      => self::DIAS_SEMANA[$fecha->dayOfWeek],
            'ventas'          => $ventas,
            'comparacion'     => $this->comparacion($owner, $fecha),
            'articulos'       => $this->articulos($owner, $inicio, $fin),
            'cobranzas'       => $this->cobranzas($owner, $fecha, $inicio, $fin),
            'compras_y_gastos' => [
                'compras' => $this->compras($owner, $inicio, $fin),
                'gastos'  => $gastos,
            ],
            'caja'            => $this->caja($owner, $inicio, $fin),
            'tienda'          => $this->tienda($owner, $inicio, $fin),
            'resultado'       => $this->resultado($owner, $fecha, $inicio, $fin, $ventas, $gastos),
        ];
    }

    /**
     * Consulta base de las ventas reales en pesos del dueño en un rango: no borradas,
     * sin consolidaciones de facturación, terminadas (mismo criterio que
     * PerformanceHelper::set_sales) y fechadas por created_at.
     *
     * @param User $owner
     * @param Carbon $desde
     * @param Carbon $hasta
     * @return \Illuminate\Database\Query\Builder
     */
    protected function consulta_ventas(User $owner, Carbon $desde, Carbon $hasta)
    {
        return DB::table('sales')
            ->where('sales.user_id', $owner->id)
            ->whereNull('sales.deleted_at')
            ->where(function ($q) {
                $q->whereNull('sales.is_consolidacion_facturacion')
                    ->orWhere('sales.is_consolidacion_facturacion', 0);
            })
            ->where('sales.terminada', 1)
            ->where(function ($q) {
                $q->whereNull('sales.moneda_id')->orWhere('sales.moneda_id', self::MONEDA_PESOS);
            })
            ->whereBetween('sales.created_at', [$desde, $hasta]);
    }

    /**
     * Cantidad y total de ventas de un rango (para la comparación).
     *
     * @param User $owner
     * @param Carbon $desde
     * @param Carbon $hasta
     * @return array{cantidad: int, total: float}
     */
    protected function cantidad_y_total(User $owner, Carbon $desde, Carbon $hasta): array
    {
        $fila = $this->consulta_ventas($owner, $desde, $hasta)
            ->selectRaw('COUNT(*) as cantidad, COALESCE(SUM(total), 0) as total')
            ->first();

        return [
            'cantidad' => (int) $fila->cantidad,
            'total'    => $this->monto($fila->total),
        ];
    }

    /**
     * Bloque `ventas`: totales, ticket promedio, a cuenta corriente, devoluciones y
     * las tres aperturas (sucursal, método de pago, vendedor).
     *
     * @param User $owner
     * @param Carbon $inicio
     * @param Carbon $fin
     * @return array
     */
    protected function ventas(User $owner, Carbon $inicio, Carbon $fin): array
    {
        $ventas = $this->consulta_ventas($owner, $inicio, $fin)
            ->get([
                'sales.id',
                'sales.total',
                'sales.total_cost',
                'sales.client_id',
                'sales.omitir_en_cuenta_corriente',
                'sales.address_id',
                'sales.employee_id',
                'sales.current_acount_payment_method_id',
            ]);

        $cantidad = $ventas->count();
        $total    = (float) $ventas->sum('total');

        // Ventas que fueron a la cuenta corriente del cliente: con cliente, sin omitir y con
        // el movimiento de cuenta corriente creado (mismo criterio que PerformanceHelper).
        $ids_con_cc = [];

        $candidatas_cc = $ventas->filter(function ($venta) {
            return !is_null($venta->client_id) && !$venta->omitir_en_cuenta_corriente;
        });

        if ($candidatas_cc->isNotEmpty()) {
            $ids_con_cc = DB::table('current_acounts')
                ->whereIn('sale_id', $candidatas_cc->pluck('id')->all())
                ->distinct()
                ->pluck('sale_id')
                ->map(function ($id) {
                    return (int) $id;
                })
                ->all();
        }

        $a_cuenta_corriente = 0.0;

        foreach ($ventas as $venta) {
            if (in_array((int) $venta->id, $ids_con_cc, true)) {
                $a_cuenta_corriente += (float) $venta->total;
            }
        }

        // Ventas de mostrador: las que NO fueron a cuenta corriente.
        $mostrador = $ventas->filter(function ($venta) use ($ids_con_cc) {
            return !in_array((int) $venta->id, $ids_con_cc, true);
        });

        return [
            'cantidad'           => $cantidad,
            'total'              => $this->monto($total),
            'ticket_promedio'    => $cantidad > 0 ? $this->monto($total / $cantidad) : null,
            'a_cuenta_corriente' => $this->monto($a_cuenta_corriente),
            'devoluciones'       => $this->devoluciones($owner, $inicio, $fin),
            'por_sucursal'       => $this->por_sucursal($owner, $ventas),
            'por_metodo_pago'    => $this->por_metodo_pago($mostrador, $a_cuenta_corriente),
            'por_vendedor'       => $this->por_vendedor($owner, $ventas),
        ];
    }

    /**
     * Notas de crédito del día en pesos (PerformanceHelper::procesar_devoluciones).
     *
     * @param User $owner
     * @param Carbon $inicio
     * @param Carbon $fin
     * @return float
     */
    protected function devoluciones(User $owner, Carbon $inicio, Carbon $fin): float
    {
        $total = DB::table('current_acounts')
            ->where('user_id', $owner->id)
            ->where('status', 'nota_credito')
            ->whereNotNull('haber')
            ->where(function ($q) {
                $q->whereNull('moneda_id')->orWhere('moneda_id', self::MONEDA_PESOS);
            })
            ->whereBetween('created_at', [$inicio, $fin])
            ->sum('haber');

        return (float) $this->monto($total);
    }

    /**
     * Ventas agrupadas por sucursal (address_id), ordenadas por total.
     *
     * @param User $owner
     * @param \Illuminate\Support\Collection $ventas
     * @return array
     */
    protected function por_sucursal(User $owner, $ventas): array
    {
        if ($ventas->isEmpty()) {
            return [];
        }

        $nombres = $this->nombres_de_sucursales($owner);
        $grupos  = [];

        foreach ($ventas as $venta) {
            $address_id = (int) $venta->address_id;

            if (!isset($grupos[$address_id])) {
                $grupos[$address_id] = [
                    'address_id' => $address_id > 0 ? $address_id : null,
                    'nombre'     => isset($nombres[$address_id]) ? $nombres[$address_id] : 'Sin sucursal',
                    'cantidad'   => 0,
                    'total'      => 0.0,
                ];
            }

            $grupos[$address_id]['cantidad']++;
            $grupos[$address_id]['total'] += (float) $venta->total;
        }

        return $this->ordenar_y_topear($grupos, 'total');
    }

    /**
     * Ingresos de las ventas de mostrador por método de pago (pivot
     * current_acount_payment_method_sale, y la venta entera al método cargado en la
     * cabecera —Efectivo si no hay— cuando no tiene pivot: PerformanceHelper::procesar_sales),
     * más una entrada "Cuenta corriente" con lo vendido a cuenta.
     *
     * @param \Illuminate\Support\Collection $mostrador Ventas que no fueron a cuenta corriente
     * @param float $a_cuenta_corriente
     * @return array
     */
    protected function por_metodo_pago($mostrador, float $a_cuenta_corriente): array
    {
        $totales = [];

        if ($mostrador->isNotEmpty()) {
            $sale_ids = $mostrador->pluck('id')->all();

            $pivots = DB::table('current_acount_payment_method_sale')
                ->whereIn('sale_id', $sale_ids)
                ->get(['sale_id', 'current_acount_payment_method_id', 'amount', 'discount_amount']);

            $ventas_con_pivot = [];

            foreach ($pivots as $pivot) {
                $metodo_id = (int) $pivot->current_acount_payment_method_id;
                $ventas_con_pivot[(int) $pivot->sale_id] = true;

                if (!isset($totales[$metodo_id])) {
                    $totales[$metodo_id] = 0.0;
                }

                $totales[$metodo_id] += (float) $pivot->amount - (float) ($pivot->discount_amount ?: 0);
            }

            foreach ($mostrador as $venta) {
                if (isset($ventas_con_pivot[(int) $venta->id])) {
                    continue;
                }

                $metodo_id = (int) $venta->current_acount_payment_method_id;

                if ($metodo_id <= 0) {
                    $metodo_id = self::METODO_PAGO_DEFAULT_ID;
                }

                if (!isset($totales[$metodo_id])) {
                    $totales[$metodo_id] = 0.0;
                }

                $totales[$metodo_id] += (float) $venta->total;
            }
        }

        $nombres = [];

        if (!empty($totales)) {
            $filas = DB::table('current_acount_payment_methods')
                ->whereIn('id', array_keys($totales))
                ->get(['id', 'name']);

            foreach ($filas as $fila) {
                $nombres[(int) $fila->id] = (string) $fila->name;
            }
        }

        $lista = [];

        foreach ($totales as $metodo_id => $total) {
            $lista[] = [
                'metodo' => isset($nombres[$metodo_id]) ? $nombres[$metodo_id] : 'Método #' . $metodo_id,
                'total'  => $total,
            ];
        }

        if ($a_cuenta_corriente > 0) {
            $lista[] = [
                'metodo' => 'Cuenta corriente',
                'total'  => $a_cuenta_corriente,
            ];
        }

        return $this->ordenar_y_topear($lista, 'total');
    }

    /**
     * Ventas por vendedor (sales.employee_id; sin empleado, el dueño).
     *
     * @param User $owner
     * @param \Illuminate\Support\Collection $ventas
     * @return array
     */
    protected function por_vendedor(User $owner, $ventas): array
    {
        if ($ventas->isEmpty()) {
            return [];
        }

        $nombres = $this->nombres_de_la_cuenta($owner);
        $grupos  = [];

        foreach ($ventas as $venta) {
            $empleado = $this->nombre_de_empleado($owner, $nombres, $venta->employee_id);

            if (!isset($grupos[$empleado])) {
                $grupos[$empleado] = [
                    'empleado' => $empleado,
                    'cantidad' => 0,
                    'total'    => 0.0,
                ];
            }

            $grupos[$empleado]['cantidad']++;
            $grupos[$empleado]['total'] += (float) $venta->total;
        }

        return $this->ordenar_y_topear($grupos, 'total');
    }

    /**
     * Bloque `comparacion`: el mismo día de la semana anterior y el promedio diario de
     * los últimos 30 días (incluido el día del informe).
     *
     * @param User $owner
     * @param Carbon $fecha
     * @return array
     */
    protected function comparacion(User $owner, Carbon $fecha): array
    {
        $semana_pasada = $fecha->copy()->subDays(7);

        $mismo_dia = $this->cantidad_y_total(
            $owner,
            $semana_pasada->copy()->startOfDay(),
            $semana_pasada->copy()->endOfDay()
        );

        $promedio = $this->cantidad_y_total(
            $owner,
            $fecha->copy()->subDays(self::DIAS_PROMEDIO - 1)->startOfDay(),
            $fecha->copy()->endOfDay()
        );

        return [
            'mismo_dia_semana_anterior' => [
                'fecha'    => $semana_pasada->format('Y-m-d'),
                'cantidad' => $mismo_dia['cantidad'],
                'total'    => $mismo_dia['total'],
            ],
            'promedio_diario_30_dias' => [
                'cantidad' => round($promedio['cantidad'] / self::DIAS_PROMEDIO, 1),
                'total'    => $this->monto($promedio['total'] / self::DIAS_PROMEDIO),
            ],
        ];
    }

    /**
     * Bloque `articulos`: más vendidos, los que volvieron a venderse tras 60 días o más
     * sin ventas, los vendidos ayer que hoy están en cero, y cuántos hay bajo el mínimo.
     *
     * @param User $owner
     * @param Carbon $inicio
     * @param Carbon $fin
     * @return array
     */
    protected function articulos(User $owner, Carbon $inicio, Carbon $fin): array
    {
        // Todo lo vendido en el día, por artículo (sin tope: alimenta las tres listas).
        $vendidos = $this->ventas_reales_desde_article_purchases(DB::table('article_purchases'), $owner->id)
            ->whereBetween('article_purchases.created_at', [$inicio, $fin])
            ->groupBy('article_purchases.article_id', 'articles.name')
            ->selectRaw(
                'article_purchases.article_id as article_id,
                 articles.name as nombre,
                 SUM(article_purchases.amount) as cantidad,
                 SUM(article_purchases.amount * article_purchases.price) as total'
            )
            ->orderByDesc('cantidad')
            ->orderBy('article_purchases.article_id')
            ->get();

        $mas_vendidos = [];
        $ids_vendidos = [];
        $cantidad_por_articulo = [];

        foreach ($vendidos as $fila) {
            $article_id = (int) $fila->article_id;
            $ids_vendidos[] = $article_id;
            $cantidad_por_articulo[$article_id] = (float) $fila->cantidad;
        }

        $top = $vendidos->take(self::TOPE_LISTA);
        $imagenes = $this->imagenes_de($top->pluck('article_id')->all());

        foreach ($top as $fila) {
            $article_id = (int) $fila->article_id;

            $mas_vendidos[] = [
                'article_id' => $article_id,
                'nombre'     => (string) $fila->nombre,
                'cantidad'   => (float) $fila->cantidad,
                'total'      => $this->monto($fila->total),
                'imagen_url' => isset($imagenes[$article_id]) ? $imagenes[$article_id] : null,
            ];
        }

        return [
            'mas_vendidos'         => $mas_vendidos,
            'volvieron_a_venderse' => $this->volvieron_a_venderse($owner, $inicio, $ids_vendidos, $cantidad_por_articulo),
            'quedaron_sin_stock'   => $this->quedaron_sin_stock($ids_vendidos),
            'bajo_minimo_total'    => $this->bajo_minimo_total($owner),
        ];
    }

    /**
     * Artículos vendidos en el día cuya venta anterior fue hace 60 días o más. Un
     * artículo que nunca se había vendido no "volvió": es nuevo, y no entra.
     *
     * @param User $owner
     * @param Carbon $inicio
     * @param array $ids_vendidos
     * @param array $cantidad_por_articulo
     * @return array
     */
    protected function volvieron_a_venderse(User $owner, Carbon $inicio, array $ids_vendidos, array $cantidad_por_articulo): array
    {
        if (empty($ids_vendidos)) {
            return [];
        }

        $limite = $inicio->copy()->subDays(self::DIAS_VOLVIO_A_VENDERSE);

        $previas = $this->ventas_reales_desde_article_purchases(DB::table('article_purchases'), $owner->id)
            ->whereIn('article_purchases.article_id', $ids_vendidos)
            ->where('article_purchases.created_at', '<', $inicio)
            ->groupBy('article_purchases.article_id', 'articles.name')
            ->selectRaw('article_purchases.article_id as article_id, articles.name as nombre, MAX(article_purchases.created_at) as ultima')
            ->havingRaw('MAX(article_purchases.created_at) <= ?', [$limite])
            ->get();

        $lista = [];

        foreach ($previas as $fila) {
            $article_id = (int) $fila->article_id;

            $lista[] = [
                'article_id'       => $article_id,
                'nombre'           => (string) $fila->nombre,
                'dias_sin_venderse' => Carbon::parse($fila->ultima)->startOfDay()->diffInDays($inicio),
                'cantidad'         => isset($cantidad_por_articulo[$article_id]) ? $cantidad_por_articulo[$article_id] : 0.0,
            ];
        }

        return $this->ordenar_y_topear($lista, 'dias_sin_venderse');
    }

    /**
     * De lo vendido en el día, lo que hoy está en cero (articles.stock <= 0).
     *
     * @param array $ids_vendidos
     * @return array
     */
    protected function quedaron_sin_stock(array $ids_vendidos): array
    {
        if (empty($ids_vendidos)) {
            return [];
        }

        $filas = DB::table('articles')
            ->whereIn('id', $ids_vendidos)
            ->whereNull('deleted_at')
            ->where(function ($q) {
                $q->whereNull('stock')->orWhere('stock', '<=', 0);
            })
            ->orderByDesc('stock_min')
            ->orderBy('id')
            ->limit(self::TOPE_LISTA)
            ->get(['id', 'name', 'stock', 'stock_min']);

        $lista = [];

        foreach ($filas as $fila) {
            $lista[] = [
                'article_id'   => (int) $fila->id,
                'nombre'       => (string) $fila->name,
                'stock'        => (float) ($fila->stock ?: 0),
                'stock_minimo' => is_null($fila->stock_min) ? null : (int) $fila->stock_min,
            ];
        }

        return $lista;
    }

    /**
     * Cuántos artículos activos tienen mínimo cargado y están por debajo.
     *
     * @param User $owner
     * @return int
     */
    protected function bajo_minimo_total(User $owner): int
    {
        return (int) DB::table('articles')
            ->where('user_id', $owner->id)
            ->whereNull('deleted_at')
            ->where('status', 'active')
            ->where('stock_min', '>', 0)
            ->whereColumn('stock', '<', 'stock_min')
            ->count();
    }

    /**
     * Bloque `cobranzas`: pagos recibidos en el día, deuda total de clientes (y su
     * variación contra la víspera, si hay snapshots) y los clientes con más deuda.
     *
     * @param User $owner
     * @param Carbon $fecha
     * @param Carbon $inicio
     * @param Carbon $fin
     * @return array
     */
    protected function cobranzas(User $owner, Carbon $fecha, Carbon $inicio, Carbon $fin): array
    {
        $pagos = DB::table('current_acounts')
            ->leftJoin('clients', 'clients.id', '=', 'current_acounts.client_id')
            ->where('current_acounts.user_id', $owner->id)
            ->where('current_acounts.status', 'pago_from_client')
            ->whereNotNull('current_acounts.haber')
            ->whereNotNull('current_acounts.client_id')
            ->where(function ($q) {
                $q->whereNull('current_acounts.moneda_id')->orWhere('current_acounts.moneda_id', self::MONEDA_PESOS);
            })
            ->whereBetween('current_acounts.created_at', [$inicio, $fin])
            ->orderByDesc('current_acounts.haber')
            ->orderBy('current_acounts.id')
            ->get(['current_acounts.client_id', 'clients.name', 'current_acounts.haber']);

        $total_cobrado = 0.0;
        $pagos_recibidos = [];

        foreach ($pagos as $pago) {
            $total_cobrado += (float) $pago->haber;

            if (count($pagos_recibidos) < self::TOPE_LISTA) {
                $pagos_recibidos[] = [
                    'client_id' => (int) $pago->client_id,
                    'nombre'    => (string) ($pago->name ?: 'Cliente #' . $pago->client_id),
                    'monto'     => $this->monto($pago->haber),
                ];
            }
        }

        return [
            'pagos_recibidos'          => $pagos_recibidos,
            'total_cobrado'            => $this->monto($total_cobrado),
            'deuda_clientes_total'     => $this->deuda_clientes_total($owner),
            'deuda_clientes_variacion' => $this->deuda_clientes_variacion($owner, $fecha),
            'clientes_con_mas_deuda'   => $this->clientes_con_mas_deuda($owner, $fecha),
        ];
    }

    /**
     * Deuda total de clientes en pesos: credit_accounts.saldo, la fuente de verdad
     * (nunca el espejo clients.saldo ni un recálculo).
     *
     * @param User $owner
     * @return float
     */
    protected function deuda_clientes_total(User $owner): float
    {
        $total = DB::table('credit_accounts')
            ->where('user_id', $owner->id)
            ->where('model_name', 'client')
            ->where(function ($q) {
                $q->whereNull('moneda_id')->orWhere('moneda_id', self::MONEDA_PESOS);
            })
            ->sum('saldo');

        return (float) $this->monto($total);
    }

    /**
     * Variación de la deuda de clientes en el día: snapshot de las 23:59 de ese día
     * menos el del día anterior (debt:snapshot). Null si falta alguno de los dos.
     *
     * @param User $owner
     * @param Carbon $fecha
     * @return float|null
     */
    protected function deuda_clientes_variacion(User $owner, Carbon $fecha)
    {
        $snapshots = DB::table('debt_snapshots')
            ->where('user_id', $owner->id)
            ->whereIn('date', [$fecha->format('Y-m-d'), $fecha->copy()->subDay()->format('Y-m-d')])
            ->get(['date', 'deuda_clientes']);

        $por_fecha = [];

        foreach ($snapshots as $snapshot) {
            $por_fecha[$this->fecha_ymd($snapshot->date)] = (float) $snapshot->deuda_clientes;
        }

        $hoy   = $fecha->format('Y-m-d');
        $ayer  = $fecha->copy()->subDay()->format('Y-m-d');

        if (!isset($por_fecha[$hoy]) || !isset($por_fecha[$ayer])) {
            return null;
        }

        return $this->monto($por_fecha[$hoy] - $por_fecha[$ayer]);
    }

    /**
     * Los clientes con más deuda en pesos, con los días desde su último pago (null si
     * nunca pagaron).
     *
     * @param User $owner
     * @param Carbon $fecha
     * @return array
     */
    protected function clientes_con_mas_deuda(User $owner, Carbon $fecha): array
    {
        $cuentas = DB::table('credit_accounts')
            ->leftJoin('clients', 'clients.id', '=', 'credit_accounts.model_id')
            ->where('credit_accounts.user_id', $owner->id)
            ->where('credit_accounts.model_name', 'client')
            ->where(function ($q) {
                $q->whereNull('credit_accounts.moneda_id')->orWhere('credit_accounts.moneda_id', self::MONEDA_PESOS);
            })
            ->where('credit_accounts.saldo', '>', 0)
            ->orderByDesc('credit_accounts.saldo')
            ->orderBy('credit_accounts.model_id')
            ->limit(self::TOPE_LISTA)
            ->get(['credit_accounts.model_id', 'clients.name', 'credit_accounts.saldo']);

        if ($cuentas->isEmpty()) {
            return [];
        }

        $client_ids = $cuentas->pluck('model_id')->map(function ($id) {
            return (int) $id;
        })->all();

        $ultimos_pagos = DB::table('current_acounts')
            ->where('user_id', $owner->id)
            ->where('status', 'pago_from_client')
            ->whereIn('client_id', $client_ids)
            ->groupBy('client_id')
            ->selectRaw('client_id, MAX(created_at) as ultimo')
            ->get();

        $ultimo_por_cliente = [];

        foreach ($ultimos_pagos as $fila) {
            $ultimo_por_cliente[(int) $fila->client_id] = $fila->ultimo;
        }

        $lista = [];

        foreach ($cuentas as $cuenta) {
            $client_id = (int) $cuenta->model_id;

            $lista[] = [
                'client_id'      => $client_id,
                'nombre'         => (string) ($cuenta->name ?: 'Cliente #' . $client_id),
                'saldo'          => $this->monto($cuenta->saldo),
                'dias_sin_pagar' => isset($ultimo_por_cliente[$client_id])
                    ? Carbon::parse($ultimo_por_cliente[$client_id])->startOfDay()->diffInDays($fecha->copy()->startOfDay())
                    : null,
            ];
        }

        return $lista;
    }

    /**
     * Compras a proveedores del día en pesos (provider_orders por created_at, como
     * PerformanceHelper::set_compras_a_proveedores).
     *
     * @param User $owner
     * @param Carbon $inicio
     * @param Carbon $fin
     * @return array
     */
    protected function compras(User $owner, Carbon $inicio, Carbon $fin): array
    {
        $ordenes = DB::table('provider_orders')
            ->leftJoin('providers', 'providers.id', '=', 'provider_orders.provider_id')
            ->where('provider_orders.user_id', $owner->id)
            ->where(function ($q) {
                $q->whereNull('provider_orders.moneda_id')->orWhere('provider_orders.moneda_id', self::MONEDA_PESOS);
            })
            ->whereBetween('provider_orders.created_at', [$inicio, $fin])
            ->get(['provider_orders.total', 'providers.name']);

        $proveedores = [];

        foreach ($ordenes as $orden) {
            $nombre = trim((string) $orden->name);

            if ($nombre !== '' && !in_array($nombre, $proveedores, true) && count($proveedores) < self::TOPE_LISTA) {
                $proveedores[] = $nombre;
            }
        }

        return [
            'cantidad'    => $ordenes->count(),
            'total'       => $this->monto($ordenes->sum('total')),
            'proveedores' => $proveedores,
        ];
    }

    /**
     * Gastos del día en pesos, agrupados por categoría (o por concepto si el gasto no
     * tiene categoría).
     *
     * @param User $owner
     * @param Carbon $inicio
     * @param Carbon $fin
     * @return array
     */
    protected function gastos(User $owner, Carbon $inicio, Carbon $fin): array
    {
        $gastos = DB::table('expenses')
            ->leftJoin('expense_categories', 'expense_categories.id', '=', 'expenses.expense_category_id')
            ->leftJoin('expense_concepts', 'expense_concepts.id', '=', 'expenses.expense_concept_id')
            ->where('expenses.user_id', $owner->id)
            ->where(function ($q) {
                $q->whereNull('expenses.moneda_id')->orWhere('expenses.moneda_id', self::MONEDA_PESOS);
            })
            ->whereBetween('expenses.created_at', [$inicio, $fin])
            ->get(['expenses.amount', 'expense_categories.name as categoria', 'expense_concepts.name as concepto']);

        $grupos = [];

        foreach ($gastos as $gasto) {
            $categoria = trim((string) $gasto->categoria);

            if ($categoria === '') {
                $categoria = trim((string) $gasto->concepto);
            }

            if ($categoria === '') {
                $categoria = 'Sin categoría';
            }

            if (!isset($grupos[$categoria])) {
                $grupos[$categoria] = ['categoria' => $categoria, 'total' => 0.0];
            }

            $grupos[$categoria]['total'] += (float) $gasto->amount;
        }

        return [
            'cantidad'      => $gastos->count(),
            'total'         => $this->monto($gastos->sum('amount')),
            'por_categoria' => $this->ordenar_y_topear($grupos, 'total'),
        ];
    }

    /**
     * Movimientos de caja del día (ingresos y egresos de todas las cajas del dueño) y
     * los cierres del día. El sistema no registra un arqueo contado contra el saldo
     * calculado: `saldo_cierre` ES el saldo calculado, así que no existe una
     * "diferencia" y viaja null en vez de inventarse un cero.
     *
     * @param User $owner
     * @param Carbon $inicio
     * @param Carbon $fin
     * @return array
     */
    protected function caja(User $owner, Carbon $inicio, Carbon $fin): array
    {
        $totales = DB::table('movimiento_cajas')
            ->join('cajas', 'cajas.id', '=', 'movimiento_cajas.caja_id')
            ->where('cajas.user_id', $owner->id)
            ->whereBetween('movimiento_cajas.created_at', [$inicio, $fin])
            ->selectRaw('COALESCE(SUM(movimiento_cajas.ingreso), 0) as ingresos, COALESCE(SUM(movimiento_cajas.egreso), 0) as egresos')
            ->first();

        $cierres = DB::table('apertura_cajas')
            ->join('cajas', 'cajas.id', '=', 'apertura_cajas.caja_id')
            ->where('cajas.user_id', $owner->id)
            ->whereBetween('apertura_cajas.cerrada_at', [$inicio, $fin])
            ->orderBy('apertura_cajas.cerrada_at')
            ->limit(self::TOPE_LISTA)
            ->get(['cajas.name']);

        $lista = [];

        foreach ($cierres as $cierre) {
            $lista[] = [
                'caja'       => (string) $cierre->name,
                'diferencia' => null,
            ];
        }

        return [
            'ingresos' => $this->monto($totales->ingresos),
            'egresos'  => $this->monto($totales->egresos),
            'cierres'  => $lista,
        ];
    }

    /**
     * Pedidos de la tienda del día.
     *
     * @param User $owner
     * @param Carbon $inicio
     * @param Carbon $fin
     * @return array
     */
    protected function tienda(User $owner, Carbon $inicio, Carbon $fin): array
    {
        $tiene_tienda = $this->tiene_tienda($owner);

        if (!$tiene_tienda) {
            return ['tiene_tienda' => false, 'pedidos' => 0, 'total' => 0.0];
        }

        $fila = DB::table('orders')
            ->where('user_id', $owner->id)
            ->whereBetween('created_at', [$inicio, $fin])
            ->selectRaw('COUNT(*) as cantidad, COALESCE(SUM(total), 0) as total')
            ->first();

        return [
            'tiene_tienda' => true,
            'pedidos'      => (int) $fila->cantidad,
            'total'        => $this->monto($fila->total),
        ];
    }

    /**
     * Resultado del día: la fila de company_performances de ese día si existe; si no,
     * la misma fórmula sobre las ventas del día (ver docblock de la clase).
     *
     * @param User $owner
     * @param Carbon $fecha
     * @param Carbon $inicio
     * @param Carbon $fin
     * @param array $ventas Bloque ya calculado
     * @param array $gastos Bloque ya calculado
     * @return array
     */
    protected function resultado(User $owner, Carbon $fecha, Carbon $inicio, Carbon $fin, array $ventas, array $gastos): array
    {
        $fila = DB::table('company_performances')
            ->where('user_id', $owner->id)
            ->where('year', $fecha->year)
            ->where('month', $fecha->month)
            ->where('day', $fecha->day)
            ->orderByDesc('id')
            ->first(['ingresos_netos', 'rentabilidad']);

        if ($fila) {
            return [
                'ingresos_netos' => $this->monto($fila->ingresos_netos),
                'rentabilidad'   => $this->monto($fila->rentabilidad),
            ];
        }

        $costo_vendido = (float) $this->consulta_ventas($owner, $inicio, $fin)->sum('total_cost');

        $ingresos_netos = (float) $ventas['total'] - (float) $ventas['devoluciones'] - $costo_vendido;

        return [
            'ingresos_netos' => $this->monto($ingresos_netos),
            'rentabilidad'   => $this->monto($ingresos_netos - (float) $gastos['total']),
        ];
    }

    /**
     * Ordena una lista de filas por una clave numérica (descendente, desempate por el
     * orden de llegada), redondea los montos y aplica el tope general.
     *
     * @param array $filas
     * @param string $clave
     * @return array
     */
    protected function ordenar_y_topear(array $filas, string $clave): array
    {
        $filas = array_values($filas);

        // usort no es estable en PHP 7.4: se conserva el índice de llegada como desempate.
        foreach ($filas as $indice => $fila) {
            $filas[$indice]['_orden'] = $indice;
        }

        usort($filas, function ($a, $b) use ($clave) {
            if ($a[$clave] == $b[$clave]) {
                return $a['_orden'] <=> $b['_orden'];
            }

            return $b[$clave] <=> $a[$clave];
        });

        $lista = [];

        foreach (array_slice($filas, 0, self::TOPE_LISTA) as $fila) {
            unset($fila['_orden']);

            if (isset($fila['total'])) {
                $fila['total'] = $this->monto($fila['total']);
            }

            $lista[] = $fila;
        }

        return $lista;
    }
}
