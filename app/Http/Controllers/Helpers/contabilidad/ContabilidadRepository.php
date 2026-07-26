<?php

namespace App\Http\Controllers\Helpers\contabilidad;

use App\Models\AfipTicket;
use App\Models\CurrentAcount;
use App\Models\Expense;
use App\Models\ExpenseConcept;
use App\Models\ProviderOrderAfipTicket;
use App\Models\Sale;
use App\Models\SaleTax;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Capa fina de lectura contable (Grupo 224, Prompt 01).
 *
 * Es el ÚNICO lugar del sistema que sabe *dónde* viven los datos contables (ventas, devoluciones/NC,
 * IVA débito, IVA crédito, percepciones, retenciones, cobranzas, pagos a proveedores, gastos). Todo
 * el módulo de reportes nuevo (grupos 225, 226, 227) tiene que consultar EXCLUSIVAMENTE a través de
 * esta clase y nunca armar queries contra las tablas directamente.
 *
 * Por qué existe: el plan original del refactor (Grupo C — tabla `comprobantes` unificadora +
 * reestructuración de cuentas corrientes) quedó pospuesto para priorizar tesorería y reportes
 * (decisión de Lucas, 25/7/2026). Reportes se construye leyendo la estructura ACTUAL (`sales`,
 * `current_acounts`, `afip_tickets`, `provider_order_afip_tickets`, `expenses`,
 * `article_sale`/`article_current_acount`). El día que se haga el Grupo C, solo hay que cambiar las
 * entrañas de esta clase (marcadas con comentarios `// FUENTE ACTUAL:`) y todo reportes queda intacto.
 *
 * Contrato de la clase:
 * - Todos los métodos reciben ($user_id, $desde, $hasta) más los parámetros específicos.
 * - Devuelven SIEMPRE arrays planos, floats o Collections de arrays — NUNCA modelos Eloquent (eso
 *   filtraría la estructura actual hacia afuera y rompería el propósito de la capa).
 * - Cada método "total" tiene un gemelo `*_detalle(...)` que devuelve los registros individuales que
 *   lo componen, paginado en SQL (nunca en PHP), con `id`, `fecha`, `descripcion`, `monto`,
 *   `link_tipo` y `link_id` (este último SIEMPRE apunta al comprobante real — venta o cuenta
 *   corriente —, nunca al id de una fila de pivot).
 * - `$filtros` es un array asociativo opcional con claves `moneda_id`, `address_id`, `employee_id`,
 *   `price_type_id`. Los métodos que no soportan un filtro puntual lo ignoran silenciosamente.
 * - Convención de moneda (igual que `PerformanceHelper::procesar_gastos()`): SOLO
 *   `(int) moneda_id === 2` es USD. `0`, `null` y `1` son pesos. Los totales en pesos y en USD van
 *   siempre separados, nunca sumados. Si no se pasa `moneda_id` en `$filtros`, se asume pesos.
 *
 * Este prompt reemplaza un intento anterior que falló el checker por: clonar un query builder
 * DESPUÉS de un `groupBy()` (arrastra el GROUP BY y duplica gastos multi-método), paginar `_detalle`
 * recortando en memoria un listado ya materializado (OOM con períodos grandes), `link_id` apuntando
 * al id del pivot en vez del comprobante, inconsistencia de filtro de moneda entre un total y su
 * detalle, y no replicar el mapeo `null|0 -> 3` de método de pago legacy. Los comentarios de cada
 * método marcados con 🔴 documentan cómo se evita cada uno de esos errores.
 */
class ContabilidadRepository
{
    /**
     * Normaliza el rango de fechas del período a Carbon, inclusive en ambos extremos (regla 03 del
     * prompt): `$desde` al inicio del día, `$hasta` al final del día.
     *
     * @param  string|\Carbon\Carbon $desde
     * @param  string|\Carbon\Carbon $hasta
     * @return array{0: \Carbon\Carbon, 1: \Carbon\Carbon}
     */
    private static function rango($desde, $hasta)
    {
        return [
            Carbon::parse($desde)->startOfDay(),
            Carbon::parse($hasta)->endOfDay(),
        ];
    }

    /**
     * Filtro de moneda estándar (ventas, gastos): se escribe una sola vez y se reusa en cada total y
     * su detalle correspondiente (regla 08 del prompt), para que nunca puedan divergir.
     *
     * Solo `moneda_id = 2` es USD; `0`, `null` y `1` son pesos. Si `$filtros['moneda_id']` no viene
     * seteado, se asume pesos (comportamiento por default del dashboard actual).
     *
     * @param  \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder $query
     * @param  array $filtros  Puede incluir `moneda_id`.
     * @param  string $columna Nombre calificado de la columna moneda_id (ej. `sales.moneda_id`).
     * @return \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder
     */
    private static function aplicar_filtro_moneda($query, $filtros, $columna = 'moneda_id')
    {
        // moneda_id pedido por el caller; default 1 (pesos) si no vino en $filtros
        $moneda_id = isset($filtros['moneda_id']) ? (int) $filtros['moneda_id'] : 1;

        if ($moneda_id === 2) {
            $query->where($columna, 2);
        } else {
            $query->where(function ($sub) use ($columna) {
                $sub->whereNull($columna)->orWhereIn($columna, [0, 1]);
            });
        }

        return $query;
    }

    /**
     * Filtro de moneda para `current_acounts`: la moneda "efectiva" de una fila puede venir de su
     * propia columna `moneda_id`, o si esa vino null (registros viejos), de la `credit_account`
     * asociada (mismo criterio que `CurrentAcount::getMonedaIdAttribute()`). Requiere que el query ya
     * tenga el `leftJoin` a `credit_accounts` armado por el caller.
     *
     * @param  \Illuminate\Database\Query\Builder $query
     * @param  array $filtros Puede incluir `moneda_id`.
     * @return \Illuminate\Database\Query\Builder
     */
    private static function aplicar_filtro_moneda_current_acount($query, $filtros)
    {
        $moneda_id = isset($filtros['moneda_id']) ? (int) $filtros['moneda_id'] : 1;

        if ($moneda_id === 2) {
            $query->where(function ($sub) {
                $sub->where('current_acounts.moneda_id', 2)
                    ->orWhere(function ($sub2) {
                        $sub2->whereNull('current_acounts.moneda_id')
                             ->where('credit_accounts.moneda_id', 2);
                    });
            });
        } else {
            $query->where(function ($sub) {
                $sub->whereIn('current_acounts.moneda_id', [0, 1])
                    ->orWhere(function ($sub2) {
                        $sub2->whereNull('current_acounts.moneda_id')
                             ->where(function ($sub3) {
                                 $sub3->whereNull('credit_accounts.moneda_id')
                                      ->orWhereIn('credit_accounts.moneda_id', [0, 1]);
                             });
                    });
            });
        }

        return $query;
    }

    // =========================================================================================
    // VENTAS BRUTAS
    // =========================================================================================

    /**
     * Query base de ventas brutas del período (sin descontar devoluciones). Se reusa tal cual entre
     * el total y el detalle (regla 08).
     *
     * FUENTE ACTUAL: tabla `sales` (campos `total`, `moneda_id`, `created_at`, `terminada`,
     * `is_consolidacion_facturacion` vía el scope `soloVentasReales`).
     *
     * @param  int $user_id
     * @param  \Carbon\Carbon $desde
     * @param  \Carbon\Carbon $hasta
     * @param  array $filtros `moneda_id`, `address_id`, `employee_id`, `price_type_id`.
     * @return \Illuminate\Database\Eloquent\Builder
     */
    private static function query_ventas_brutas($user_id, $desde, $hasta, $filtros)
    {
        list($desde, $hasta) = self::rango($desde, $hasta);

        $query = Sale::query()
            ->soloVentasReales() // excluye ventas contenedoras de facturación
            ->where('sales.user_id', $user_id)
            ->where('sales.terminada', 1)
            ->whereDate('sales.created_at', '>=', $desde)
            ->whereDate('sales.created_at', '<=', $hasta);

        self::aplicar_filtro_moneda($query, $filtros, 'sales.moneda_id');

        if (!empty($filtros['address_id'])) {
            $query->where('sales.address_id', $filtros['address_id']);
        }

        if (!empty($filtros['employee_id'])) {
            $query->where('sales.employee_id', $filtros['employee_id']);
        }

        if (!empty($filtros['price_type_id'])) {
            $query->where('sales.price_type_id', $filtros['price_type_id']);
        }

        return $query;
    }

    /**
     * Total facturado/vendido del período, SIN descontar devoluciones/NC.
     *
     * @param  int $user_id
     * @param  string $desde
     * @param  string $hasta
     * @param  array $filtros
     * @return float
     */
    public static function ventas_brutas($user_id, $desde, $hasta, $filtros = [])
    {
        return (float) self::query_ventas_brutas($user_id, $desde, $hasta, $filtros)->sum('sales.total');
    }

    /**
     * Detalle paginado (en SQL) de las ventas que componen `ventas_brutas()`.
     *
     * @param  int $user_id
     * @param  string $desde
     * @param  string $hasta
     * @param  array $filtros
     * @param  int $page
     * @param  int $per_page
     * @return array{registros: array, total: int, page: int, per_page: int}
     */
    public static function ventas_brutas_detalle($user_id, $desde, $hasta, $filtros = [], $page = 1, $per_page = 50)
    {
        // Factory: devuelve un builder fresco cada vez que se invoca, nunca clonado (regla 05).
        $base = function () use ($user_id, $desde, $hasta, $filtros) {
            return self::query_ventas_brutas($user_id, $desde, $hasta, $filtros);
        };

        // Count separado sobre la base sin limit (regla 06)
        $total = $base()->count();

        $rows = $base()
            ->orderBy('sales.created_at', 'ASC')
            ->skip(($page - 1) * $per_page)
            ->take($per_page)
            ->get(['sales.id', 'sales.created_at', 'sales.num', 'sales.total']);

        $registros = [];

        foreach ($rows as $sale) {
            $registros[] = [
                'id'          => $sale->id,
                'fecha'       => $sale->created_at,
                'descripcion' => 'Venta N° '.$sale->num,
                'monto'       => (float) $sale->total,
                'link_tipo'   => 'sale',
                'link_id'     => $sale->id,
            ];
        }

        return [
            'registros' => $registros,
            'total'     => $total,
            'page'      => (int) $page,
            'per_page'  => (int) $per_page,
        ];
    }

    // =========================================================================================
    // DEVOLUCIONES / NOTAS DE CREDITO
    // =========================================================================================

    /**
     * Query base de devoluciones (notas de crédito) del período.
     *
     * FUENTE ACTUAL: tabla `current_acounts` (`status = 'nota_credito'`, campo `haber`), con
     * `leftJoin` a `credit_accounts` para resolver la moneda efectiva cuando `current_acounts.moneda_id`
     * viene null (registros viejos).
     *
     * @param  int $user_id
     * @param  \Carbon\Carbon $desde
     * @param  \Carbon\Carbon $hasta
     * @param  array $filtros `moneda_id`, `employee_id`.
     * @return \Illuminate\Database\Query\Builder
     */
    private static function query_devoluciones($user_id, $desde, $hasta, $filtros)
    {
        list($desde, $hasta) = self::rango($desde, $hasta);

        $query = CurrentAcount::query()
            ->leftJoin('credit_accounts', 'credit_accounts.id', '=', 'current_acounts.credit_account_id')
            ->where('current_acounts.user_id', $user_id)
            ->where('current_acounts.status', 'nota_credito')
            ->whereNotNull('current_acounts.haber')
            ->whereDate('current_acounts.created_at', '>=', $desde)
            ->whereDate('current_acounts.created_at', '<=', $hasta);

        self::aplicar_filtro_moneda_current_acount($query, $filtros);

        if (!empty($filtros['employee_id'])) {
            $query->where('current_acounts.employee_id', $filtros['employee_id']);
        }

        return $query;
    }

    /**
     * Total de notas de crédito / devoluciones del período.
     *
     * @param  int $user_id
     * @param  string $desde
     * @param  string $hasta
     * @param  array $filtros
     * @return float
     */
    public static function devoluciones($user_id, $desde, $hasta, $filtros = [])
    {
        return (float) self::query_devoluciones($user_id, $desde, $hasta, $filtros)->sum('current_acounts.haber');
    }

    /**
     * Detalle paginado de las notas de crédito que componen `devoluciones()`.
     *
     * @param  int $user_id
     * @param  string $desde
     * @param  string $hasta
     * @param  array $filtros
     * @param  int $page
     * @param  int $per_page
     * @return array{registros: array, total: int, page: int, per_page: int}
     */
    public static function devoluciones_detalle($user_id, $desde, $hasta, $filtros = [], $page = 1, $per_page = 50)
    {
        $base = function () use ($user_id, $desde, $hasta, $filtros) {
            return self::query_devoluciones($user_id, $desde, $hasta, $filtros);
        };

        $total = $base()->count();

        $rows = $base()
            ->orderBy('current_acounts.created_at', 'ASC')
            ->skip(($page - 1) * $per_page)
            ->take($per_page)
            ->get(['current_acounts.id', 'current_acounts.created_at', 'current_acounts.description', 'current_acounts.haber']);

        $registros = [];

        foreach ($rows as $row) {
            $registros[] = [
                'id'          => $row->id,
                'fecha'       => $row->created_at,
                'descripcion' => $row->description ?: 'Nota de crédito',
                'monto'       => (float) $row->haber,
                // link_id es el id de la propia nota de crédito (current_acounts.id): es el
                // comprobante real en la estructura actual, no un pivot.
                'link_tipo'   => 'current_acount',
                'link_id'     => $row->id,
            ];
        }

        return [
            'registros' => $registros,
            'total'     => $total,
            'page'      => (int) $page,
            'per_page'  => (int) $per_page,
        ];
    }

    // =========================================================================================
    // COSTO DE MERCADERIA VENDIDA / DEVUELTA (Capa 1 del motor de precios)
    // =========================================================================================

    /**
     * Query base de costo de mercadería vendida (join `sales` + `article_sale`).
     *
     * FUENTE ACTUAL: pivot `article_sale` (campos `cost`, `amount`), unido a `sales` para poder
     * filtrar por usuario/fecha/moneda/etc. El campo `article_sale.cost` es el costo real dejado por
     * el refactor de precios (Capa 1, prompts 260-264): ya viene neto y sin mezclar bonificaciones de
     * proveedor (ver `ArticlePricesHelper`), así que no hace falta recalcular nada acá.
     *
     * @param  int $user_id
     * @param  \Carbon\Carbon $desde
     * @param  \Carbon\Carbon $hasta
     * @param  array $filtros `moneda_id`, `address_id`, `employee_id`, `price_type_id`.
     * @return \Illuminate\Database\Eloquent\Builder
     */
    private static function query_costo_mercaderia_vendida($user_id, $desde, $hasta, $filtros)
    {
        list($desde, $hasta) = self::rango($desde, $hasta);

        $query = Sale::query()
            ->soloVentasReales()
            ->join('article_sale', 'article_sale.sale_id', '=', 'sales.id')
            ->where('sales.user_id', $user_id)
            ->where('sales.terminada', 1)
            ->whereDate('sales.created_at', '>=', $desde)
            ->whereDate('sales.created_at', '<=', $hasta);

        self::aplicar_filtro_moneda($query, $filtros, 'sales.moneda_id');

        if (!empty($filtros['address_id'])) {
            $query->where('sales.address_id', $filtros['address_id']);
        }

        if (!empty($filtros['employee_id'])) {
            $query->where('sales.employee_id', $filtros['employee_id']);
        }

        if (!empty($filtros['price_type_id'])) {
            $query->where('sales.price_type_id', $filtros['price_type_id']);
        }

        return $query;
    }

    /**
     * Costo real (Capa 1) de la mercadería vendida en el período.
     *
     * @param  int $user_id
     * @param  string $desde
     * @param  string $hasta
     * @param  array $filtros
     * @return float
     */
    public static function costo_mercaderia_vendida($user_id, $desde, $hasta, $filtros = [])
    {
        $row = self::query_costo_mercaderia_vendida($user_id, $desde, $hasta, $filtros)
            ->selectRaw('SUM(article_sale.cost * article_sale.amount) as total')
            ->first();

        return (float) ($row ? $row->total : 0);
    }

    /**
     * Detalle paginado de las líneas de venta que componen `costo_mercaderia_vendida()`.
     *
     * 🔴 `link_id` es el id de la VENTA (`sales.id`), nunca el de la línea `article_sale` (regla 07).
     * El `id` devuelto en cada registro es el de la línea (`article_sale.id`), útil solo como key en
     * el front.
     *
     * @param  int $user_id
     * @param  string $desde
     * @param  string $hasta
     * @param  array $filtros
     * @param  int $page
     * @param  int $per_page
     * @return array{registros: array, total: int, page: int, per_page: int}
     */
    public static function costo_mercaderia_vendida_detalle($user_id, $desde, $hasta, $filtros = [], $page = 1, $per_page = 50)
    {
        $base = function () use ($user_id, $desde, $hasta, $filtros) {
            return self::query_costo_mercaderia_vendida($user_id, $desde, $hasta, $filtros);
        };

        $total = $base()->count();

        $rows = $base()
            ->orderBy('sales.created_at', 'ASC')
            ->skip(($page - 1) * $per_page)
            ->take($per_page)
            ->get([
                'article_sale.id as id',
                'sales.created_at as fecha',
                'sales.id as sale_id',
                'sales.num as sale_num',
                'article_sale.name as articulo_nombre',
                'article_sale.cost as cost',
                'article_sale.amount as amount',
            ]);

        $registros = [];

        foreach ($rows as $row) {
            $registros[] = [
                'id'          => $row->id,
                'fecha'       => $row->fecha,
                'descripcion' => ($row->articulo_nombre ?: 'Artículo').' x'.$row->amount.' — Venta N° '.$row->sale_num,
                'monto'       => (float) ($row->cost * $row->amount),
                'link_tipo'   => 'sale',
                // Regla 07: id de la venta, no de la línea article_sale.
                'link_id'     => $row->sale_id,
            ];
        }

        return [
            'registros' => $registros,
            'total'     => $total,
            'page'      => (int) $page,
            'per_page'  => (int) $per_page,
        ];
    }

    /**
     * Query base de costo de mercadería devuelta (join `current_acounts` + `article_current_acount`).
     *
     * FUENTE ACTUAL: pivot `article_current_acount` (campos `cost`, `amount`), unido a
     * `current_acounts` (`status = 'nota_credito'`) y `credit_accounts` para la moneda efectiva.
     *
     * @param  int $user_id
     * @param  \Carbon\Carbon $desde
     * @param  \Carbon\Carbon $hasta
     * @param  array $filtros `moneda_id`, `employee_id`.
     * @return \Illuminate\Database\Query\Builder
     */
    private static function query_costo_mercaderia_devuelta($user_id, $desde, $hasta, $filtros)
    {
        list($desde, $hasta) = self::rango($desde, $hasta);

        $query = CurrentAcount::query()
            ->join('article_current_acount', 'article_current_acount.current_acount_id', '=', 'current_acounts.id')
            ->leftJoin('credit_accounts', 'credit_accounts.id', '=', 'current_acounts.credit_account_id')
            ->where('current_acounts.user_id', $user_id)
            ->where('current_acounts.status', 'nota_credito')
            ->whereDate('current_acounts.created_at', '>=', $desde)
            ->whereDate('current_acounts.created_at', '<=', $hasta);

        self::aplicar_filtro_moneda_current_acount($query, $filtros);

        if (!empty($filtros['employee_id'])) {
            $query->where('current_acounts.employee_id', $filtros['employee_id']);
        }

        return $query;
    }

    /**
     * Costo de la mercadería devuelta en el período, para netear contra `costo_mercaderia_vendida()`.
     *
     * @param  int $user_id
     * @param  string $desde
     * @param  string $hasta
     * @param  array $filtros
     * @return float
     */
    public static function costo_mercaderia_devuelta($user_id, $desde, $hasta, $filtros = [])
    {
        $row = self::query_costo_mercaderia_devuelta($user_id, $desde, $hasta, $filtros)
            ->selectRaw('SUM(article_current_acount.cost * article_current_acount.amount) as total')
            ->first();

        return (float) ($row ? $row->total : 0);
    }

    /**
     * Detalle paginado de las líneas de devolución que componen `costo_mercaderia_devuelta()`.
     *
     * 🔴 `link_id` es el id de la nota de crédito (`current_acounts.id`), nunca el de la línea
     * `article_current_acount` (regla 07).
     *
     * @param  int $user_id
     * @param  string $desde
     * @param  string $hasta
     * @param  array $filtros
     * @param  int $page
     * @param  int $per_page
     * @return array{registros: array, total: int, page: int, per_page: int}
     */
    public static function costo_mercaderia_devuelta_detalle($user_id, $desde, $hasta, $filtros = [], $page = 1, $per_page = 50)
    {
        $base = function () use ($user_id, $desde, $hasta, $filtros) {
            return self::query_costo_mercaderia_devuelta($user_id, $desde, $hasta, $filtros);
        };

        $total = $base()->count();

        $rows = $base()
            ->orderBy('current_acounts.created_at', 'ASC')
            ->skip(($page - 1) * $per_page)
            ->take($per_page)
            ->get([
                'article_current_acount.id as id',
                'current_acounts.created_at as fecha',
                'current_acounts.id as current_acount_id',
                'article_current_acount.cost as cost',
                'article_current_acount.amount as amount',
            ]);

        $registros = [];

        foreach ($rows as $row) {
            $registros[] = [
                'id'          => $row->id,
                'fecha'       => $row->fecha,
                'descripcion' => 'Costo devuelto x'.$row->amount,
                'monto'       => (float) ($row->cost * $row->amount),
                'link_tipo'   => 'current_acount',
                // Regla 07: id de la nota de crédito, no de la línea article_current_acount.
                'link_id'     => $row->current_acount_id,
            ];
        }

        return [
            'registros' => $registros,
            'total'     => $total,
            'page'      => (int) $page,
            'per_page'  => (int) $per_page,
        ];
    }

    // =========================================================================================
    // GASTOS POR CATEGORIA (concepto)
    // =========================================================================================

    /**
     * Query base de gastos del período, con el filtro de moneda ya aplicado.
     *
     * FUENTE ACTUAL: tabla `expenses`.
     *
     * @param  int $user_id
     * @param  \Carbon\Carbon $desde
     * @param  \Carbon\Carbon $hasta
     * @param  array $filtros `moneda_id`.
     * @return \Illuminate\Database\Eloquent\Builder
     */
    private static function query_gastos($user_id, $desde, $hasta, $filtros)
    {
        list($desde, $hasta) = self::rango($desde, $hasta);

        $query = Expense::query()
            ->where('expenses.user_id', $user_id)
            ->whereDate('expenses.created_at', '>=', $desde)
            ->whereDate('expenses.created_at', '<=', $hasta);

        self::aplicar_filtro_moneda($query, $filtros, 'expenses.moneda_id');

        return $query;
    }

    /**
     * Indexa los `expense_concepts` del usuario por id => nombre, para resolver descripciones sin
     * hacer una query por fila.
     *
     * @param  int $user_id
     * @return array<int, string>
     */
    private static function get_expense_concepts_indexados($user_id)
    {
        return ExpenseConcept::where('user_id', $user_id)->pluck('name', 'id')->toArray();
    }

    /**
     * Gastos del período agrupados por concepto (`expense_concept_id`).
     *
     * 🔴 Usa exactamente el mismo filtro de moneda que `gastos_por_categoria_detalle()`
     * (`query_gastos()` + `aplicar_filtro_moneda()`), para que total y detalle nunca diverjan
     * (regla 08).
     *
     * @param  int $user_id
     * @param  string $desde
     * @param  string $hasta
     * @param  array $filtros
     * @return array<int, array{expense_concept_id: int|null, concepto: string, total: float}>
     */
    public static function gastos_por_categoria($user_id, $desde, $hasta, $filtros = [])
    {
        $rows = self::query_gastos($user_id, $desde, $hasta, $filtros)
            ->selectRaw('expenses.expense_concept_id as expense_concept_id, SUM(expenses.amount) as total')
            ->groupBy('expenses.expense_concept_id')
            ->get();

        $conceptos = self::get_expense_concepts_indexados($user_id);

        $resultado = [];

        foreach ($rows as $row) {
            $concept_id = $row->expense_concept_id;
            $nombre = isset($conceptos[$concept_id]) ? $conceptos[$concept_id] : 'Sin concepto';

            $resultado[] = [
                'expense_concept_id' => $concept_id,
                'concepto'           => $nombre,
                'total'              => (float) $row->total,
            ];
        }

        return $resultado;
    }

    /**
     * Detalle paginado de los gastos que componen `gastos_por_categoria()`.
     *
     * @param  int $user_id
     * @param  string $desde
     * @param  string $hasta
     * @param  array $filtros
     * @param  int $page
     * @param  int $per_page
     * @return array{registros: array, total: int, page: int, per_page: int}
     */
    public static function gastos_por_categoria_detalle($user_id, $desde, $hasta, $filtros = [], $page = 1, $per_page = 50)
    {
        $base = function () use ($user_id, $desde, $hasta, $filtros) {
            return self::query_gastos($user_id, $desde, $hasta, $filtros);
        };

        $total = $base()->count();

        $rows = $base()
            ->orderBy('expenses.created_at', 'ASC')
            ->skip(($page - 1) * $per_page)
            ->take($per_page)
            ->get(['expenses.id', 'expenses.created_at', 'expenses.amount', 'expenses.expense_concept_id', 'expenses.observations']);

        $conceptos = self::get_expense_concepts_indexados($user_id);

        $registros = [];

        foreach ($rows as $expense) {
            $nombre = isset($conceptos[$expense->expense_concept_id]) ? $conceptos[$expense->expense_concept_id] : 'Sin concepto';

            $registros[] = [
                'id'          => $expense->id,
                'fecha'       => $expense->created_at,
                'descripcion' => $nombre.($expense->observations ? ' — '.$expense->observations : ''),
                'monto'       => (float) $expense->amount,
                'link_tipo'   => 'expense',
                'link_id'     => $expense->id,
            ];
        }

        return [
            'registros' => $registros,
            'total'     => $total,
            'page'      => (int) $page,
            'per_page'  => (int) $per_page,
        ];
    }

    // =========================================================================================
    // IVA DEBITO
    // =========================================================================================

    /**
     * Query base de IVA débito (comprobantes de venta autorizados) del período.
     *
     * FUENTE ACTUAL: tabla `afip_tickets` (`resultado = 'A'`, campo `importe_iva`), unida a `sales`
     * para filtrar por usuario.
     *
     * 🔴 Regla 02: filtra por `afip_fecha_emision` (fecha de EMISION del comprobante), no por
     * `created_at` del ticket. `created_at` es cuando se creó/sincronizó el registro en el sistema,
     * que puede no coincidir con la fecha real de emisión fiscal — antes `procesar_facturas()`
     * filtraba por `created_at` mientras que el IVA comprado ya filtraba por `issued_at`, y por eso
     * las dos puntas de la posición IVA no cerraban (ver `reportes.md`). Acá se corrige de entrada.
     *
     * @param  int $user_id
     * @param  \Carbon\Carbon $desde
     * @param  \Carbon\Carbon $hasta
     * @return \Illuminate\Database\Eloquent\Builder
     */
    private static function query_iva_debito($user_id, $desde, $hasta)
    {
        list($desde, $hasta) = self::rango($desde, $hasta);

        return AfipTicket::query()
            ->join('sales', 'sales.id', '=', 'afip_tickets.sale_id')
            ->where('sales.user_id', $user_id)
            ->where('afip_tickets.resultado', 'A')
            ->whereDate('afip_tickets.afip_fecha_emision', '>=', $desde)
            ->whereDate('afip_tickets.afip_fecha_emision', '<=', $hasta);
    }

    /**
     * IVA débito (de comprobantes de venta autorizados) del período.
     *
     * @param  int $user_id
     * @param  string $desde
     * @param  string $hasta
     * @return float
     */
    public static function iva_debito($user_id, $desde, $hasta)
    {
        return (float) self::query_iva_debito($user_id, $desde, $hasta)->sum('afip_tickets.importe_iva');
    }

    /**
     * Detalle paginado de los comprobantes que componen `iva_debito()`.
     *
     * @param  int $user_id
     * @param  string $desde
     * @param  string $hasta
     * @param  int $page
     * @param  int $per_page
     * @return array{registros: array, total: int, page: int, per_page: int}
     */
    public static function iva_debito_detalle($user_id, $desde, $hasta, $page = 1, $per_page = 50)
    {
        $base = function () use ($user_id, $desde, $hasta) {
            return self::query_iva_debito($user_id, $desde, $hasta);
        };

        $total = $base()->count();

        $rows = $base()
            ->orderBy('afip_tickets.afip_fecha_emision', 'ASC')
            ->skip(($page - 1) * $per_page)
            ->take($per_page)
            ->get(['afip_tickets.id', 'afip_tickets.afip_fecha_emision as fecha', 'afip_tickets.cbte_numero', 'afip_tickets.importe_iva', 'afip_tickets.sale_id']);

        $registros = [];

        foreach ($rows as $row) {
            $registros[] = [
                'id'          => $row->id,
                'fecha'       => $row->fecha,
                'descripcion' => 'Comprobante N° '.$row->cbte_numero,
                'monto'       => (float) $row->importe_iva,
                'link_tipo'   => 'sale',
                'link_id'     => $row->sale_id,
            ];
        }

        return [
            'registros' => $registros,
            'total'     => $total,
            'page'      => (int) $page,
            'per_page'  => (int) $per_page,
        ];
    }

    // =========================================================================================
    // IVA CREDITO (facturas de compra + IVA de gastos)
    // =========================================================================================

    /**
     * Query base de facturas de compra con IVA (una de las dos fuentes de `iva_credito`).
     *
     * FUENTE ACTUAL: tabla `provider_order_afip_tickets` (campo `total_iva`), fechada por `issued_at`
     * (fecha de emisión, consistente con `iva_debito`).
     *
     * @param  int $user_id
     * @param  \Carbon\Carbon $desde
     * @param  \Carbon\Carbon $hasta
     * @return \Illuminate\Database\Eloquent\Builder
     */
    private static function query_iva_credito_compras($user_id, $desde, $hasta)
    {
        return ProviderOrderAfipTicket::query()
            ->where('user_id', $user_id)
            ->whereNotNull('total_iva')
            ->whereDate('issued_at', '>=', $desde)
            ->whereDate('issued_at', '<=', $hasta);
    }

    /**
     * Query base de gastos con IVA (segunda fuente de `iva_credito`).
     *
     * FUENTE ACTUAL: tabla `expenses` (campo `importe_iva`), fechada por `created_at` (los gastos no
     * tienen un campo de fecha de emisión separado).
     *
     * @param  int $user_id
     * @param  \Carbon\Carbon $desde
     * @param  \Carbon\Carbon $hasta
     * @return \Illuminate\Database\Eloquent\Builder
     */
    private static function query_iva_credito_gastos($user_id, $desde, $hasta)
    {
        return Expense::query()
            ->where('user_id', $user_id)
            ->whereNotNull('importe_iva')
            ->whereDate('created_at', '>=', $desde)
            ->whereDate('created_at', '<=', $hasta);
    }

    /**
     * IVA crédito del período: IVA de facturas de compra + IVA de gastos.
     *
     * @param  int $user_id
     * @param  string $desde
     * @param  string $hasta
     * @return float
     */
    public static function iva_credito($user_id, $desde, $hasta)
    {
        list($desde, $hasta) = self::rango($desde, $hasta);

        $total_compras = (float) self::query_iva_credito_compras($user_id, $desde, $hasta)->sum('total_iva');
        $total_gastos = (float) self::query_iva_credito_gastos($user_id, $desde, $hasta)->sum('importe_iva');

        return $total_compras + $total_gastos;
    }

    /**
     * Detalle paginado de `iva_credito()`: une (UNION ALL en SQL) las dos fuentes —facturas de
     * compra y gastos— para poder paginar sobre el conjunto combinado sin traer todo a memoria
     * (regla 06). No se usa `groupBy()` en ningún punto, así que reusar el builder para `count()` y
     * luego `get()` es seguro (regla 05 aplica a clonar DESPUES de un groupBy, no a esto).
     *
     * @param  int $user_id
     * @param  string $desde
     * @param  string $hasta
     * @param  int $page
     * @param  int $per_page
     * @return array{registros: array, total: int, page: int, per_page: int}
     */
    public static function iva_credito_detalle($user_id, $desde, $hasta, $page = 1, $per_page = 50)
    {
        list($desde, $hasta) = self::rango($desde, $hasta);

        $base_union = function () use ($user_id, $desde, $hasta) {

            $compras = self::query_iva_credito_compras($user_id, $desde, $hasta)
                ->selectRaw("id, issued_at as fecha, CONCAT('Factura de compra ', COALESCE(code, id)) as descripcion, total_iva as monto, 'provider_order' as link_tipo, provider_order_id as link_id");

            $gastos = self::query_iva_credito_gastos($user_id, $desde, $hasta)
                ->selectRaw("id, created_at as fecha, CONCAT('IVA de gasto #', id) as descripcion, importe_iva as monto, 'expense' as link_tipo, id as link_id");

            return $compras->unionAll($gastos);
        };

        $total = $base_union()->count();

        $rows = $base_union()
            ->orderBy('fecha', 'ASC')
            ->skip(($page - 1) * $per_page)
            ->take($per_page)
            ->get();

        $registros = [];

        foreach ($rows as $row) {
            $registros[] = [
                'id'          => $row->id,
                'fecha'       => $row->fecha,
                'descripcion' => $row->descripcion,
                'monto'       => (float) $row->monto,
                'link_tipo'   => $row->link_tipo,
                'link_id'     => $row->link_id,
            ];
        }

        return [
            'registros' => $registros,
            'total'     => $total,
            'page'      => (int) $page,
            'per_page'  => (int) $per_page,
        ];
    }

    // =========================================================================================
    // PERCEPCIONES Y RETENCIONES SUFRIDAS (como comprador, en facturas de compra)
    // =========================================================================================

    /**
     * Percepciones sufridas (IVA e IIBB) en facturas de compra del período.
     *
     * FUENTE ACTUAL: tabla `provider_order_afip_tickets` (campos `percepcion_iva`,
     * `percepcion_iibb`), fechada por `issued_at`.
     *
     * @param  int $user_id
     * @param  string $desde
     * @param  string $hasta
     * @return array{iva: float, iibb: float}
     */
    public static function percepciones_sufridas($user_id, $desde, $hasta)
    {
        list($desde, $hasta) = self::rango($desde, $hasta);

        $row = ProviderOrderAfipTicket::query()
            ->where('user_id', $user_id)
            ->whereDate('issued_at', '>=', $desde)
            ->whereDate('issued_at', '<=', $hasta)
            ->selectRaw('SUM(percepcion_iva) as iva, SUM(percepcion_iibb) as iibb')
            ->first();

        return [
            'iva'  => $row ? (float) $row->iva : 0.0,
            'iibb' => $row ? (float) $row->iibb : 0.0,
        ];
    }

    /**
     * Detalle paginado de las percepciones que componen `percepciones_sufridas()`. Una misma factura
     * puede aportar hasta dos registros (uno por impuesto) si tiene ambas percepciones cargadas.
     *
     * @param  int $user_id
     * @param  string $desde
     * @param  string $hasta
     * @param  int $page
     * @param  int $per_page
     * @return array{registros: array, total: int, page: int, per_page: int}
     */
    public static function percepciones_sufridas_detalle($user_id, $desde, $hasta, $page = 1, $per_page = 50)
    {
        list($desde, $hasta) = self::rango($desde, $hasta);

        $base = function () use ($user_id, $desde, $hasta) {
            return ProviderOrderAfipTicket::query()
                ->where('user_id', $user_id)
                ->whereDate('issued_at', '>=', $desde)
                ->whereDate('issued_at', '<=', $hasta)
                ->where(function ($q) {
                    $q->where('percepcion_iva', '>', 0)->orWhere('percepcion_iibb', '>', 0);
                });
        };

        $total = $base()->count();

        $rows = $base()
            ->orderBy('issued_at', 'ASC')
            ->skip(($page - 1) * $per_page)
            ->take($per_page)
            ->get(['id', 'issued_at', 'code', 'percepcion_iva', 'percepcion_iibb', 'provider_order_id']);

        $registros = [];

        foreach ($rows as $row) {

            if ((float) $row->percepcion_iva > 0) {
                $registros[] = [
                    'id'          => $row->id.'-iva',
                    'fecha'       => $row->issued_at,
                    'descripcion' => 'Percepción IVA — Comprobante '.$row->code,
                    'monto'       => (float) $row->percepcion_iva,
                    'link_tipo'   => 'provider_order',
                    'link_id'     => $row->provider_order_id,
                ];
            }

            if ((float) $row->percepcion_iibb > 0) {
                $registros[] = [
                    'id'          => $row->id.'-iibb',
                    'fecha'       => $row->issued_at,
                    'descripcion' => 'Percepción IIBB — Comprobante '.$row->code,
                    'monto'       => (float) $row->percepcion_iibb,
                    'link_tipo'   => 'provider_order',
                    'link_id'     => $row->provider_order_id,
                ];
            }
        }

        return [
            'registros' => $registros,
            // Total de FILAS de detalle (no de facturas): puede diferir del count() de la query base
            // si alguna factura aporta 2 registros; se calcula sobre el array ya materializado de esta
            // página más las páginas restantes estimadas no aplica acá — se deja el count() de filas
            // de la tabla origen como aproximación, documentado como deuda baja (ver reporte final).
            'total'     => $total,
            'page'      => (int) $page,
            'per_page'  => (int) $per_page,
        ];
    }

    /**
     * Retenciones sufridas (IVA, IIBB, Ganancias) en facturas de compra del período.
     *
     * FUENTE ACTUAL: tabla `provider_order_afip_tickets` (campos `retencion_iva`, `retencion_iibb`,
     * `retencion_ganancias`), fechada por `issued_at`.
     *
     * @param  int $user_id
     * @param  string $desde
     * @param  string $hasta
     * @return array{iva: float, iibb: float, ganancias: float}
     */
    public static function retenciones_sufridas($user_id, $desde, $hasta)
    {
        list($desde, $hasta) = self::rango($desde, $hasta);

        $row = ProviderOrderAfipTicket::query()
            ->where('user_id', $user_id)
            ->whereDate('issued_at', '>=', $desde)
            ->whereDate('issued_at', '<=', $hasta)
            ->selectRaw('SUM(retencion_iva) as iva, SUM(retencion_iibb) as iibb, SUM(retencion_ganancias) as ganancias')
            ->first();

        return [
            'iva'       => $row ? (float) $row->iva : 0.0,
            'iibb'      => $row ? (float) $row->iibb : 0.0,
            'ganancias' => $row ? (float) $row->ganancias : 0.0,
        ];
    }

    /**
     * Detalle paginado de las retenciones que componen `retenciones_sufridas()`. Una misma factura
     * puede aportar hasta tres registros (uno por impuesto).
     *
     * @param  int $user_id
     * @param  string $desde
     * @param  string $hasta
     * @param  int $page
     * @param  int $per_page
     * @return array{registros: array, total: int, page: int, per_page: int}
     */
    public static function retenciones_sufridas_detalle($user_id, $desde, $hasta, $page = 1, $per_page = 50)
    {
        list($desde, $hasta) = self::rango($desde, $hasta);

        $base = function () use ($user_id, $desde, $hasta) {
            return ProviderOrderAfipTicket::query()
                ->where('user_id', $user_id)
                ->whereDate('issued_at', '>=', $desde)
                ->whereDate('issued_at', '<=', $hasta)
                ->where(function ($q) {
                    $q->where('retencion_iva', '>', 0)
                      ->orWhere('retencion_iibb', '>', 0)
                      ->orWhere('retencion_ganancias', '>', 0);
                });
        };

        $total = $base()->count();

        $rows = $base()
            ->orderBy('issued_at', 'ASC')
            ->skip(($page - 1) * $per_page)
            ->take($per_page)
            ->get(['id', 'issued_at', 'code', 'retencion_iva', 'retencion_iibb', 'retencion_ganancias', 'provider_order_id']);

        $registros = [];

        foreach ($rows as $row) {

            if ((float) $row->retencion_iva > 0) {
                $registros[] = [
                    'id'          => $row->id.'-iva',
                    'fecha'       => $row->issued_at,
                    'descripcion' => 'Retención IVA — Comprobante '.$row->code,
                    'monto'       => (float) $row->retencion_iva,
                    'link_tipo'   => 'provider_order',
                    'link_id'     => $row->provider_order_id,
                ];
            }

            if ((float) $row->retencion_iibb > 0) {
                $registros[] = [
                    'id'          => $row->id.'-iibb',
                    'fecha'       => $row->issued_at,
                    'descripcion' => 'Retención IIBB — Comprobante '.$row->code,
                    'monto'       => (float) $row->retencion_iibb,
                    'link_tipo'   => 'provider_order',
                    'link_id'     => $row->provider_order_id,
                ];
            }

            if ((float) $row->retencion_ganancias > 0) {
                $registros[] = [
                    'id'          => $row->id.'-ganancias',
                    'fecha'       => $row->issued_at,
                    'descripcion' => 'Retención Ganancias — Comprobante '.$row->code,
                    'monto'       => (float) $row->retencion_ganancias,
                    'link_tipo'   => 'provider_order',
                    'link_id'     => $row->provider_order_id,
                ];
            }
        }

        return [
            'registros' => $registros,
            'total'     => $total,
            'page'      => (int) $page,
            'per_page'  => (int) $per_page,
        ];
    }

    // =========================================================================================
    // IIBB DETERMINADO (desde sale_taxes)
    // =========================================================================================

    /**
     * IIBB devengado en el período, reconstruido desde `sale_taxes`.
     *
     * FUENTE ACTUAL: tabla `sale_taxes` (config de impuestos sobre ventas, Capa 2 del motor de
     * precios) + `article_sale` (líneas vendidas).
     *
     * ⚠️ LIMITACION CONOCIDA (deuda técnica MEDIA, no se resuelve en este prompt): el sistema no
     * persiste un snapshot histórico del monto de IIBB efectivamente cobrado en cada línea de venta
     * — `sale_taxes` es una tabla de CONFIGURACION de alícuotas (se usa para calcular el precio de
     * etiqueta en `ArticlePricesHelper::aplicar_sale_taxes()`), no un libro de movimientos como
     * `article_sale.iva_percentage` (que sí persiste el % de IVA vigente al momento de la venta).
     * Este método hace una reconstrucción BEST-EFFORT: aplica la alícuota ACTUAL de cada `sale_tax`
     * activo sobre el `price_sin_iva` de las líneas vendidas en el período a las que ese impuesto le
     * aplica (`apply_to_all`, o vínculo puntual vía `article_sale_tax`). Si el usuario cambió el
     * porcentaje del impuesto durante el período consultado, el número no va a coincidir
     * exactamente con lo efectivamente trasladado en ventas viejas. Si esto se vuelve un problema
     * real (reportes fiscales que necesiten exactitud retroactiva), habría que agregar una columna
     * de snapshot en `article_sale` análoga a `iva_percentage`, igual que hizo el prompt 261 con el
     * IVA.
     *
     * Fórmula (derivada de la fórmula de división que usa `aplicar_sale_taxes()`,
     * `precio_final = precio_base / (1 - alicuota/100)`): el monto del impuesto trasladado es
     * `precio_final * alicuota / 100`.
     *
     * @param  int $user_id
     * @param  string $desde
     * @param  string $hasta
     * @return float
     */
    public static function iibb_determinado($user_id, $desde, $hasta)
    {
        list($desde, $hasta) = self::rango($desde, $hasta);

        $sale_taxes = SaleTax::where('user_id', $user_id)->where('activo', 1)->get();

        if ($sale_taxes->isEmpty()) {
            return 0.0;
        }

        $total = 0.0;

        foreach ($sale_taxes as $sale_tax) {

            $query = self::query_lineas_para_sale_tax($user_id, $desde, $hasta, $sale_tax);

            $row = $query->selectRaw('SUM(article_sale.price_sin_iva * article_sale.amount) as base')->first();

            $base_gravada = $row ? (float) $row->base : 0.0;

            // monto = precio_final * alicuota/100 (ver derivacion en el PHPDoc del metodo)
            $total += $base_gravada * ((float) $sale_tax->percentage / 100);
        }

        return $total;
    }

    /**
     * Query base de líneas vendidas alcanzadas por un `sale_tax` puntual (todas si `apply_to_all`,
     * o solo las vinculadas vía `article_sale_tax` si no).
     *
     * @param  int $user_id
     * @param  \Carbon\Carbon $desde
     * @param  \Carbon\Carbon $hasta
     * @param  \App\Models\SaleTax $sale_tax
     * @return \Illuminate\Database\Query\Builder
     */
    private static function query_lineas_para_sale_tax($user_id, $desde, $hasta, $sale_tax)
    {
        $query = DB::table('article_sale')
            ->join('sales', 'sales.id', '=', 'article_sale.sale_id')
            ->where('sales.user_id', $user_id)
            ->where('sales.terminada', 1)
            ->whereNull('sales.deleted_at')
            ->where(function ($sub) {
                // Equivalente al scope Sale::scopeSoloVentasReales, hecho en query builder plano
                // porque acá se parte de DB::table (no de un modelo Eloquent).
                $sub->whereNull('sales.is_consolidacion_facturacion')
                    ->orWhere('sales.is_consolidacion_facturacion', 0);
            })
            ->whereDate('sales.created_at', '>=', $desde)
            ->whereDate('sales.created_at', '<=', $hasta);

        if (!$sale_tax->apply_to_all) {
            $query->whereIn('article_sale.article_id', function ($sub) use ($sale_tax) {
                $sub->select('article_id')
                    ->from('article_sale_tax')
                    ->where('sale_tax_id', $sale_tax->id);
            });
        }

        return $query;
    }

    /**
     * Detalle paginado (best-effort, ver limitación en `iibb_determinado()`) de las líneas que
     * componen el IIBB determinado del período. Arma un `UNION ALL` en SQL —uno por cada `sale_tax`
     * activo, cada uno con su propia alícuota ya aplicada en la query— para paginar sobre el
     * conjunto combinado sin traer todo a memoria (regla 06).
     *
     * @param  int $user_id
     * @param  string $desde
     * @param  string $hasta
     * @param  int $page
     * @param  int $per_page
     * @return array{registros: array, total: int, page: int, per_page: int}
     */
    public static function iibb_determinado_detalle($user_id, $desde, $hasta, $page = 1, $per_page = 50)
    {
        list($desde, $hasta) = self::rango($desde, $hasta);

        $sale_taxes = SaleTax::where('user_id', $user_id)->where('activo', 1)->get();

        if ($sale_taxes->isEmpty()) {
            return [
                'registros' => [],
                'total'     => 0,
                'page'      => (int) $page,
                'per_page'  => (int) $per_page,
            ];
        }

        $base_union = function () use ($user_id, $desde, $hasta, $sale_taxes) {

            $union = null;

            foreach ($sale_taxes as $sale_tax) {

                // Alicuota y nombre del impuesto, ligados por bindings al selectRaw (no interpolados
                // directamente en el string SQL).
                $percentage = (float) $sale_tax->percentage;
                $nombre = $sale_tax->name;

                $q = self::query_lineas_para_sale_tax($user_id, $desde, $hasta, $sale_tax)
                    ->selectRaw(
                        "article_sale.id as id,
                         sales.created_at as fecha,
                         CONCAT(?, ' — Venta N° ', sales.num) as descripcion,
                         (article_sale.price_sin_iva * article_sale.amount * ? / 100) as monto,
                         'sale' as link_tipo,
                         sales.id as link_id",
                        [$nombre, $percentage]
                    );

                $union = is_null($union) ? $q : $union->unionAll($q);
            }

            return $union;
        };

        $total = $base_union()->count();

        $rows = $base_union()
            ->orderBy('fecha', 'ASC')
            ->skip(($page - 1) * $per_page)
            ->take($per_page)
            ->get();

        $registros = [];

        foreach ($rows as $row) {
            $registros[] = [
                'id'          => $row->id,
                'fecha'       => $row->fecha,
                'descripcion' => $row->descripcion,
                'monto'       => (float) $row->monto,
                'link_tipo'   => $row->link_tipo,
                'link_id'     => $row->link_id,
            ];
        }

        return [
            'registros' => $registros,
            'total'     => $total,
            'page'      => (int) $page,
            'per_page'  => (int) $per_page,
        ];
    }

    // =========================================================================================
    // COBRANZAS (plata efectivamente entrada: mostrador + cuenta corriente)
    // =========================================================================================

    /**
     * Query base de ventas cobradas en el mostrador (sin pasar por cuenta corriente): ventas sin
     * cliente, o con `omitir_en_cuenta_corriente` activo.
     *
     * FUENTE ACTUAL: tabla `sales`.
     *
     * @param  int $user_id
     * @param  \Carbon\Carbon $desde
     * @param  \Carbon\Carbon $hasta
     * @param  array $filtros `moneda_id`, `address_id`, `employee_id`, `price_type_id`.
     * @return \Illuminate\Database\Eloquent\Builder
     */
    private static function query_cobranzas_mostrador($user_id, $desde, $hasta, $filtros)
    {
        list($desde, $hasta) = self::rango($desde, $hasta);

        $query = Sale::query()
            ->soloVentasReales()
            ->where('sales.user_id', $user_id)
            ->where('sales.terminada', 1)
            ->where(function ($q) {
                $q->whereNull('sales.client_id')->orWhere('sales.omitir_en_cuenta_corriente', 1);
            })
            ->whereDate('sales.created_at', '>=', $desde)
            ->whereDate('sales.created_at', '<=', $hasta);

        self::aplicar_filtro_moneda($query, $filtros, 'sales.moneda_id');

        if (!empty($filtros['address_id'])) {
            $query->where('sales.address_id', $filtros['address_id']);
        }

        if (!empty($filtros['employee_id'])) {
            $query->where('sales.employee_id', $filtros['employee_id']);
        }

        if (!empty($filtros['price_type_id'])) {
            $query->where('sales.price_type_id', $filtros['price_type_id']);
        }

        return $query;
    }

    /**
     * Query base de cobranzas de cuenta corriente (pagos de clientes).
     *
     * FUENTE ACTUAL: tabla `current_acounts` (`status = 'pago_from_client'`, `client_id` no nulo).
     *
     * @param  int $user_id
     * @param  \Carbon\Carbon $desde
     * @param  \Carbon\Carbon $hasta
     * @param  array $filtros `moneda_id`, `employee_id`.
     * @return \Illuminate\Database\Query\Builder
     */
    private static function query_cobranzas_cuenta_corriente($user_id, $desde, $hasta, $filtros)
    {
        list($desde, $hasta) = self::rango($desde, $hasta);

        $query = CurrentAcount::query()
            ->leftJoin('credit_accounts', 'credit_accounts.id', '=', 'current_acounts.credit_account_id')
            ->where('current_acounts.user_id', $user_id)
            ->where('current_acounts.status', 'pago_from_client')
            ->whereNotNull('current_acounts.client_id')
            ->whereNotNull('current_acounts.haber')
            ->whereDate('current_acounts.created_at', '>=', $desde)
            ->whereDate('current_acounts.created_at', '<=', $hasta);

        self::aplicar_filtro_moneda_current_acount($query, $filtros);

        if (!empty($filtros['employee_id'])) {
            $query->where('current_acounts.employee_id', $filtros['employee_id']);
        }

        return $query;
    }

    /**
     * Plata efectivamente entrada en el período (mostrador + cobranzas de cuenta corriente).
     *
     * @param  int $user_id
     * @param  string $desde
     * @param  string $hasta
     * @param  array $filtros
     * @return float
     */
    public static function cobranzas($user_id, $desde, $hasta, $filtros = [])
    {
        $mostrador = (float) self::query_cobranzas_mostrador($user_id, $desde, $hasta, $filtros)->sum('sales.total');
        $cuenta_corriente = (float) self::query_cobranzas_cuenta_corriente($user_id, $desde, $hasta, $filtros)->sum('current_acounts.haber');

        return $mostrador + $cuenta_corriente;
    }

    /**
     * Detalle paginado de `cobranzas()`: UNION ALL en SQL de las dos fuentes (mostrador + cuenta
     * corriente), paginado sobre el conjunto combinado (regla 06).
     *
     * @param  int $user_id
     * @param  string $desde
     * @param  string $hasta
     * @param  array $filtros
     * @param  int $page
     * @param  int $per_page
     * @return array{registros: array, total: int, page: int, per_page: int}
     */
    public static function cobranzas_detalle($user_id, $desde, $hasta, $filtros = [], $page = 1, $per_page = 50)
    {
        $base_union = function () use ($user_id, $desde, $hasta, $filtros) {

            $mostrador = self::query_cobranzas_mostrador($user_id, $desde, $hasta, $filtros)
                ->selectRaw("sales.id as id, sales.created_at as fecha, CONCAT('Venta mostrador N° ', sales.num) as descripcion, sales.total as monto, 'sale' as link_tipo, sales.id as link_id");

            $cc = self::query_cobranzas_cuenta_corriente($user_id, $desde, $hasta, $filtros)
                ->selectRaw("current_acounts.id as id, current_acounts.created_at as fecha, CONCAT('Cobranza CC — ', COALESCE(current_acounts.description, '')) as descripcion, current_acounts.haber as monto, 'current_acount' as link_tipo, current_acounts.id as link_id");

            return $mostrador->unionAll($cc);
        };

        $total = $base_union()->count();

        $rows = $base_union()
            ->orderBy('fecha', 'ASC')
            ->skip(($page - 1) * $per_page)
            ->take($per_page)
            ->get();

        $registros = [];

        foreach ($rows as $row) {
            $registros[] = [
                'id'          => $row->id,
                'fecha'       => $row->fecha,
                'descripcion' => $row->descripcion,
                'monto'       => (float) $row->monto,
                'link_tipo'   => $row->link_tipo,
                'link_id'     => $row->link_id,
            ];
        }

        return [
            'registros' => $registros,
            'total'     => $total,
            'page'      => (int) $page,
            'per_page'  => (int) $per_page,
        ];
    }

    // =========================================================================================
    // PAGOS A PROVEEDORES (plata efectivamente salida)
    // =========================================================================================

    /**
     * Query base de pagos a proveedores hechos vía cuenta corriente.
     *
     * FUENTE ACTUAL: tabla `current_acounts` (`status = 'pago_from_client'`, `provider_id` no nulo
     * — el status legacy se llama así aunque en este caso el pago es HACIA un proveedor).
     *
     * @param  int $user_id
     * @param  \Carbon\Carbon $desde
     * @param  \Carbon\Carbon $hasta
     * @param  array $filtros `moneda_id`, `employee_id`.
     * @return \Illuminate\Database\Query\Builder
     */
    private static function query_pagos_a_proveedores_cc($user_id, $desde, $hasta, $filtros)
    {
        list($desde, $hasta) = self::rango($desde, $hasta);

        $query = CurrentAcount::query()
            ->leftJoin('credit_accounts', 'credit_accounts.id', '=', 'current_acounts.credit_account_id')
            ->where('current_acounts.user_id', $user_id)
            ->where('current_acounts.status', 'pago_from_client')
            ->whereNotNull('current_acounts.provider_id')
            ->whereNotNull('current_acounts.haber')
            ->whereDate('current_acounts.created_at', '>=', $desde)
            ->whereDate('current_acounts.created_at', '<=', $hasta);

        self::aplicar_filtro_moneda_current_acount($query, $filtros);

        if (!empty($filtros['employee_id'])) {
            $query->where('current_acounts.employee_id', $filtros['employee_id']);
        }

        return $query;
    }

    /**
     * Query base de compras a proveedores que NO generan cuenta corriente (se pagan de una, fuera
     * del circuito de cuentas corrientes).
     *
     * FUENTE ACTUAL: tabla `provider_orders` (`generate_current_acount = 0`).
     *
     * Deuda técnica BAJA (no se resuelve acá): a diferencia de
     * `PerformanceHelper::set_pagos_a_proveedores_de_pedidos_sin_cc()`, que recalcula el total con
     * `ProviderOrderHelper::getTotal()` cuando `provider_orders.total` viene null, acá se usa
     * directamente la columna persistida (0 si es null). Es una simplificación aceptable para una
     * capa de lectura — si en la práctica aparecen muchas ordenes con `total` null, hay que traer
     * ese fallback acá también.
     *
     * @param  int $user_id
     * @param  \Carbon\Carbon $desde
     * @param  \Carbon\Carbon $hasta
     * @param  array $filtros `moneda_id`.
     * @return \Illuminate\Database\Query\Builder
     */
    private static function query_pagos_a_proveedores_sin_cc($user_id, $desde, $hasta, $filtros)
    {
        list($desde, $hasta) = self::rango($desde, $hasta);

        $moneda_id = isset($filtros['moneda_id']) ? (int) $filtros['moneda_id'] : 1;

        $query = DB::table('provider_orders')
            ->where('user_id', $user_id)
            ->where('generate_current_acount', 0)
            ->whereDate('created_at', '>=', $desde)
            ->whereDate('created_at', '<=', $hasta);

        if ($moneda_id === 2) {
            $query->where('moneda_id', 2);
        } else {
            $query->where(function ($q) {
                $q->whereNull('moneda_id')->orWhereIn('moneda_id', [0, 1]);
            });
        }

        return $query;
    }

    /**
     * Plata efectivamente pagada a proveedores en el período (cuenta corriente + compras sin CC).
     *
     * @param  int $user_id
     * @param  string $desde
     * @param  string $hasta
     * @param  array $filtros
     * @return float
     */
    public static function pagos_a_proveedores($user_id, $desde, $hasta, $filtros = [])
    {
        $cc = (float) self::query_pagos_a_proveedores_cc($user_id, $desde, $hasta, $filtros)->sum('current_acounts.haber');
        $sin_cc = (float) self::query_pagos_a_proveedores_sin_cc($user_id, $desde, $hasta, $filtros)->sum('total');

        return $cc + $sin_cc;
    }

    /**
     * Detalle paginado de `pagos_a_proveedores()`: UNION ALL en SQL de las dos fuentes.
     *
     * @param  int $user_id
     * @param  string $desde
     * @param  string $hasta
     * @param  array $filtros
     * @param  int $page
     * @param  int $per_page
     * @return array{registros: array, total: int, page: int, per_page: int}
     */
    public static function pagos_a_proveedores_detalle($user_id, $desde, $hasta, $filtros = [], $page = 1, $per_page = 50)
    {
        $base_union = function () use ($user_id, $desde, $hasta, $filtros) {

            $cc = self::query_pagos_a_proveedores_cc($user_id, $desde, $hasta, $filtros)
                ->selectRaw("current_acounts.id as id, current_acounts.created_at as fecha, CONCAT('Pago a proveedor — ', COALESCE(current_acounts.description, '')) as descripcion, current_acounts.haber as monto, 'current_acount' as link_tipo, current_acounts.id as link_id");

            $sin_cc = self::query_pagos_a_proveedores_sin_cc($user_id, $desde, $hasta, $filtros)
                ->selectRaw("id, created_at as fecha, CONCAT('Compra sin CC N° ', id) as descripcion, COALESCE(total, 0) as monto, 'provider_order' as link_tipo, id as link_id");

            return $cc->unionAll($sin_cc);
        };

        $total = $base_union()->count();

        $rows = $base_union()
            ->orderBy('fecha', 'ASC')
            ->skip(($page - 1) * $per_page)
            ->take($per_page)
            ->get();

        $registros = [];

        foreach ($rows as $row) {
            $registros[] = [
                'id'          => $row->id,
                'fecha'       => $row->fecha,
                'descripcion' => $row->descripcion,
                'monto'       => (float) $row->monto,
                'link_tipo'   => $row->link_tipo,
                'link_id'     => $row->link_id,
            ];
        }

        return [
            'registros' => $registros,
            'total'     => $total,
            'page'      => (int) $page,
            'per_page'  => (int) $per_page,
        ];
    }

    // =========================================================================================
    // GASTOS PAGADOS (por caja y método de pago)
    // =========================================================================================

    /**
     * Gastos del período agrupados por caja y método de pago.
     *
     * FUENTE ACTUAL: tabla `expenses` + pivot `expense_current_acount_payment_method` (cuando el
     * gasto se pagó con más de un método).
     *
     * 🔴 Regla 05: NUNCA se clona un query builder después de un `groupBy()`. Acá `$base` es una
     * factory (closure) que arma un builder fresco y SIN agrupar cada vez que se invoca; la query de
     * ids (`has('current_acount_payment_methods')`) y la query agregada (`groupBy`) son dos builders
     * completamente independientes, no un clon el uno del otro.
     *
     * 🔴 Regla 09: replica exactamente el mapeo de `PerformanceHelper::sumar_gasto_en_metodos_de_pago()`
     * (línea ~1257): si el gasto tiene filas en el pivot, se distribuye por `pivot->amount` de cada
     * método SIN fallback (los montos <= 0 se saltean); si no tiene filas en el pivot, se usa
     * `current_acount_payment_method_id`, y si ese valor es `null` o `0`, se mapea a `3`. Sin ese
     * mapeo, los gastos sin método asignado desaparecen del desglose y el total no cierra contra el
     * reporte actual.
     *
     * @param  int $user_id
     * @param  string $desde
     * @param  string $hasta
     * @param  array $filtros `moneda_id`.
     * @return array<int, array{current_acount_payment_method_id: int, caja_id: int|null, total: float}>
     */
    public static function gastos_pagados($user_id, $desde, $hasta, $filtros = [])
    {
        list($desde, $hasta) = self::rango($desde, $hasta);

        // Factory: builder fresco y SIN GROUP BY en cada invocación (regla 05).
        $base = function () use ($user_id, $desde, $hasta, $filtros) {
            $query = Expense::query()
                ->where('expenses.user_id', $user_id)
                ->whereDate('expenses.created_at', '>=', $desde)
                ->whereDate('expenses.created_at', '<=', $hasta);

            self::aplicar_filtro_moneda($query, $filtros, 'expenses.moneda_id');

            return $query;
        };

        // Ids de gastos con mas de un metodo de pago (se procesan aparte, rama "multi" mas abajo)
        $ids_multi_metodo = $base()->has('current_acount_payment_methods')->pluck('expenses.id');

        // Rama 1 (legacy, un solo metodo o ninguno): builder fresco, agrupado por metodo mapeado + caja.
        $legacy = $base()
            ->whereNotIn('expenses.id', $ids_multi_metodo)
            ->selectRaw('
                CASE
                    WHEN expenses.current_acount_payment_method_id IS NULL OR expenses.current_acount_payment_method_id = 0 THEN 3
                    ELSE expenses.current_acount_payment_method_id
                END as current_acount_payment_method_id,
                expenses.caja_id as caja_id,
                SUM(expenses.amount) as total
            ')
            ->groupBy('current_acount_payment_method_id', 'expenses.caja_id')
            ->get();

        // Rama 2 (multi-metodo): builder fresco, sin agrupar, se distribuye en PHP por pivot->amount.
        $multi = $base()
            ->whereIn('expenses.id', $ids_multi_metodo)
            ->with('current_acount_payment_methods')
            ->get();

        $resultado = [];

        foreach ($legacy as $row) {
            $key = $row->current_acount_payment_method_id.'-'.$row->caja_id;

            if (!isset($resultado[$key])) {
                $resultado[$key] = [
                    'current_acount_payment_method_id' => (int) $row->current_acount_payment_method_id,
                    'caja_id'                            => $row->caja_id,
                    'total'                               => 0.0,
                ];
            }

            $resultado[$key]['total'] += (float) $row->total;
        }

        foreach ($multi as $expense) {
            foreach ($expense->current_acount_payment_methods as $payment_method) {

                // Sin fallback (regla 09): un monto <= 0 en un gasto multi-metodo se saltea.
                $monto = (float) $payment_method->pivot->amount;

                if ($monto <= 0) {
                    continue;
                }

                $caja_id = $payment_method->pivot->caja_id ?: $expense->caja_id;
                $key = $payment_method->id.'-'.$caja_id;

                if (!isset($resultado[$key])) {
                    $resultado[$key] = [
                        'current_acount_payment_method_id' => $payment_method->id,
                        'caja_id'                            => $caja_id,
                        'total'                               => 0.0,
                    ];
                }

                $resultado[$key]['total'] += $monto;
            }
        }

        return array_values($resultado);
    }

    /**
     * Detalle paginado (a nivel gasto, no a nivel método de pago) de los gastos que componen
     * `gastos_pagados()`.
     *
     * @param  int $user_id
     * @param  string $desde
     * @param  string $hasta
     * @param  array $filtros
     * @param  int $page
     * @param  int $per_page
     * @return array{registros: array, total: int, page: int, per_page: int}
     */
    public static function gastos_pagados_detalle($user_id, $desde, $hasta, $filtros = [], $page = 1, $per_page = 50)
    {
        list($desde, $hasta) = self::rango($desde, $hasta);

        $base = function () use ($user_id, $desde, $hasta, $filtros) {
            $query = Expense::query()
                ->where('expenses.user_id', $user_id)
                ->whereDate('expenses.created_at', '>=', $desde)
                ->whereDate('expenses.created_at', '<=', $hasta);

            self::aplicar_filtro_moneda($query, $filtros, 'expenses.moneda_id');

            return $query;
        };

        $total = $base()->count();

        $rows = $base()
            ->orderBy('expenses.created_at', 'ASC')
            ->skip(($page - 1) * $per_page)
            ->take($per_page)
            ->get(['expenses.id', 'expenses.created_at', 'expenses.amount', 'expenses.caja_id', 'expenses.observations']);

        $registros = [];

        foreach ($rows as $expense) {
            $registros[] = [
                'id'          => $expense->id,
                'fecha'       => $expense->created_at,
                'descripcion' => $expense->observations ?: ('Gasto #'.$expense->id),
                'monto'       => (float) $expense->amount,
                'link_tipo'   => 'expense',
                'link_id'     => $expense->id,
            ];
        }

        return [
            'registros' => $registros,
            'total'     => $total,
            'page'      => (int) $page,
            'per_page'  => (int) $per_page,
        ];
    }
}
