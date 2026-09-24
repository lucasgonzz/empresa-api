<?php

namespace App\Http\Controllers\Helpers\sale;

use App\Models\Sale;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;

/**
 * Modo paginado del listado de ventas por fecha (`GET sale/from-date/{modulo}/{from_date?}/{until_date?}`).
 *
 * Hasta esta mision el endpoint bajaba TODAS las ventas del dia (o del rango) con `withAll()`, y la
 * pantalla filtraba, sumaba y contaba en el navegador. Aca eso se da vuelta: la pagina sale con
 * `LIMIT`, y los totales, la cantidad y los contadores de las solapas salen de agregados en SQL
 * sobre columnas persistidas de `sales`, sin cargar ninguna venta.
 *
 * 🔴 EL MODO ES OPT-IN POR `per_page`. Sin `per_page` en la query string el controller responde
 * exactamente lo de siempre (`{ models: [...] }`, sin paginar). No es por prolijidad: los modulos
 * `por_entregar`, `por_estado` y `deposito` de la SPA leen el LISTADO ENTERO del mismo store
 * (`state.sale.models`) y pegan a este mismo endpoint con otro `modulo`. Paginarles la respuesta
 * les recortaria la pantalla sin ningun error. Solo la pantalla de Ventas manda `per_page`.
 *
 * Los filtros que se llevan a SQL son el espejo de `src/mixins/sale.js::sales_to_show` (y de su
 * copia en PHP, `SaleController::apply_view_show_option_filters`, que el Excel sigue usando sobre
 * la coleccion cargada). Si se cambia el criterio de un filtro, se cambia en los tres lados.
 */
class ListadoVentasHelper
{
    /** Tamaño de pagina cuando `per_page` viene invalido (<1): el mismo que el input "Por pagina" de la SPA. */
    const PER_PAGE_POR_DEFECTO = 25;

    /** Techo de `per_page`: mas que esto vuelve a ser "bajar el dia entero", que es lo que se quiere evitar. */
    const PER_PAGE_MAXIMO = 200;

    /**
     * Estado de la cuenta corriente de la venta tal como lo ve el navegador: el `status` de la
     * PRIMERA fila de `current_acounts` de esa venta (el `hasOne` `Sale::current_acount`). Ver el
     * comentario del filtro de cobradas en `aplicar_filtros_de_pantalla`. `CurrentAcount` no usa
     * soft delete, asi que no hace falta filtrar `deleted_at`.
     */
    const SUBQUERY_ESTADO_DE_LA_CUENTA_CORRIENTE = '(SELECT ca.status FROM current_acounts ca WHERE ca.sale_id = sales.id ORDER BY ca.id ASC LIMIT 1)';

    /**
     * Indica si el pedido activa el modo paginado.
     *
     * Se mira la presencia del parametro en la QUERY STRING, no su valor: `per_page=0` activa el
     * modo (y cae al default), y un `per_page` en el cuerpo no cuenta porque este endpoint es GET.
     *
     * @param  Request $request
     * @return bool
     */
    static function pide_paginado(Request $request)
    {
        return $request->query->has('per_page');
    }

    /**
     * `per_page` saneado: <1 cae al default y >200 se acota al techo. Misma regla que
     * `ProviderController` / `ClientController`, con los numeros de este listado.
     *
     * @param  Request $request
     * @return int
     */
    static function per_page(Request $request)
    {
        $per_page = (int) $request->query('per_page');

        if ($per_page < 1) {
            return self::PER_PAGE_POR_DEFECTO;
        }

        if ($per_page > self::PER_PAGE_MAXIMO) {
            return self::PER_PAGE_MAXIMO;
        }

        return $per_page;
    }

    /**
     * Pagina pedida. Se lee de la query string y nunca del cuerpo, igual que `per_page`.
     *
     * @param  Request $request
     * @return int
     */
    static function page(Request $request)
    {
        $page = (int) $request->query('page', 1);

        return $page < 1 ? 1 : $page;
    }

    /**
     * Arma la respuesta del modo paginado a partir de la query base del controller
     * (usuario + modulo + fecha, SIN `orderBy` ni `withAll`).
     *
     * Del builder base salen CINCO consultas (mas hasta dos de totales sin IVA, ver
     * `totales_sin_iva()`, que reciben el conjunto como subselect de ids), y cada una parte de un
     * `clone` propio: el Builder de Eloquent implementa `__clone` clonando el query builder de
     * abajo, asi que los `where` que le agrega una consulta no se le pegan a la siguiente. Sin el
     * clone, la de conteos por solapa arrastraria las show options de la de totales y las solapas
     * cambiarian al tocar un filtro.
     *
     * @param  \Illuminate\Database\Eloquent\Builder $query_base Query del controller: user + modulo + fecha.
     * @param  Request $request
     * @param  string  $modulo   `ventas`, `por_entregar`, `por_estado`... El de la ruta.
     * @param  int     $user_id  Dueño de las ventas (para la preferencia de imagenes de articulos).
     * @return array   ['models' => LengthAwarePaginator, 'totales' => array]
     */
    static function respuesta_paginada($query_base, Request $request, $modulo, $user_id)
    {
        $per_page = self::per_page($request);
        $page = self::page($request);

        /* Conjunto BASE: lo que la SPA llama `this.sales` (dia + modulo + to_check/checked + consolidadas). */
        $base = self::aplicar_base(clone $query_base, $request, $modulo);

        /* Conjunto FILTRADO: lo que la SPA llama `sales_to_show` (base + solapas + show options). Es el que pagina. */
        $filtrada = self::aplicar_filtros_de_pantalla(clone $base, $request);

        /*
         * Los agregados se piden una sola vez y de ahi salen los totales de siempre Y el rango de
         * `created_at` del conjunto, que necesitan los totales sin IVA de mas abajo.
         */
        $agregados = self::agregados($filtrada);

        $totales = self::totales_de_los_agregados($agregados);

        $totales['pesos'] = array_merge(
            $totales['pesos'],
            self::totales_sin_iva($filtrada, $user_id, $totales['pesos']['costos'], $agregados)
        );

        /*
         * El COUNT de la consulta de totales es el `total` del paginador. No se llama a `paginate()`
         * porque volveria a contar el mismo conjunto: dos consultas sobre el dia entero donde alcanza
         * con una. Y si no hay filas no se pide la pagina, igual que hace `paginate()`.
         */
        $filas = $totales['cantidad'] > 0
            ? self::filas_de_la_pagina($filtrada, $page, $per_page, $user_id)
            : $query_base->getModel()->newCollection();

        $paginador = new LengthAwarePaginator($filas, $totales['cantidad'], $per_page, $page, [
            'path'     => Paginator::resolveCurrentPath(),
            'pageName' => 'page',
        ]);

        $totales['metodo_de_pago'] = self::total_del_metodo_de_pago($filtrada, $request);

        return [
            'models'  => $paginador,
            'totales' => array_merge($totales, self::conteos_por_solapa($base)),
        ];
    }

    /**
     * Espejo de `pasaFilaVenta` del mixin de ventas de la SPA: saca las ventas en revision
     * (`to_check` / `checked`) y, salvo que se pidan, las contenedoras de consolidacion.
     *
     * El corte por `to_check`/`checked` es solo para el modulo `ventas`: `por_entregar` son
     * justamente esas.
     *
     * @param  \Illuminate\Database\Eloquent\Builder $query
     * @param  Request $request
     * @param  string  $modulo
     * @return \Illuminate\Database\Eloquent\Builder
     */
    static function aplicar_base($query, Request $request, $modulo)
    {
        if ($modulo == 'ventas') {
            /* Null-safe aunque las columnas sean NOT NULL DEFAULT 0: es la misma regla que el `!sale.to_check` del front. */
            $query->where(function ($q) {
                    $q->whereNull('sales.to_check')
                      ->orWhere('sales.to_check', 0);
                })
                ->where(function ($q) {
                    $q->whereNull('sales.checked')
                      ->orWhere('sales.checked', 0);
                });
        }

        if (!self::mostrar_consolidadas($request)) {
            $query->soloVentasReales();
        }

        return $query;
    }

    /**
     * Espejo de `sales_to_show`: solapa de sucursal, solapa de empleado y las tres show options.
     *
     * Con "ver consolidadas" prendido, una contenedora sin `address_id` / `employee_id` pasa la
     * solapa igual (`_mostrarContenedorConSucursal` / `_mostrarContenedorConEmpleado`), y pasa el
     * filtro de metodo de pago igual: las contenedoras no tienen medios de pago propios.
     *
     * @param  \Illuminate\Database\Eloquent\Builder $query
     * @param  Request $request
     * @return \Illuminate\Database\Eloquent\Builder
     */
    static function aplicar_filtros_de_pantalla($query, Request $request)
    {
        $consolidadas = self::mostrar_consolidadas($request);

        /* Solapa de sucursal. */
        $address_id = $request->query('address_id');

        if (!is_null($address_id) && $address_id !== '') {
            $query->where(function ($q) use ($address_id, $consolidadas) {
                $q->where('sales.address_id', $address_id);

                if ($consolidadas) {
                    $q->orWhere(function ($q2) {
                        $q2->where('sales.is_consolidacion_facturacion', 1)
                           ->whereNull('sales.address_id');
                    });
                }
            });
        }

        /* Solapa de empleado. "Dueño" (only_owner) manda sobre employee_id, igual que en el Excel. */
        $only_owner = self::bandera($request, 'only_owner');
        $employee_id = $request->query('employee_id');

        if ($only_owner) {
            /* `!sale.employee_id` en el front: NULL y 0 son las dos formas de "sin empleado". */
            $query->where(function ($q) {
                $q->whereNull('sales.employee_id')
                  ->orWhere('sales.employee_id', 0);
            });
        } else if (!is_null($employee_id) && $employee_id !== '') {
            $query->where(function ($q) use ($employee_id, $consolidadas) {
                $q->where('sales.employee_id', $employee_id);

                if ($consolidadas) {
                    $q->orWhere(function ($q2) {
                        $q2->where('sales.is_consolidacion_facturacion', 1)
                           ->whereNull('sales.employee_id');
                    });
                }
            });
        }

        /*
         * Show option cobradas / sin cobrar (espejo de `venta_cobrada` de mixins/generals.js).
         *
         * 🔴 Se mira UNA fila de `current_acounts` por venta -- la primera por id --, no "alguna".
         * Es lo que mira el navegador: `sale.current_acount` es un `hasOne`, o sea la primera fila
         * que Eloquent trae para ese `sale_id`, y esa es el debito de la venta (se crea al vender).
         * Una devolucion a cuenta corriente agrega DESPUES una segunda fila con el mismo `sale_id`
         * y `status = 'nota_credito'` (`CurrentAcountHelper::notaCredito`). Con un `EXISTS(status <>
         * 'pagado')` esa venta, aunque su deuda ya este pagada, entraba en "sin cobrar" y sumaba en
         * el chip; el navegador la escondia despues y la pagina quedaba corta. No es un caso raro:
         * es cualquier venta a cuenta corriente con una devolucion.
         */
        $cobradas = $request->query('ventas_cobradas_show_option', 'cobradas-y-no-cobradas');

        $estado_de_la_cuenta = self::SUBQUERY_ESTADO_DE_LA_CUENTA_CORRIENTE;

        if ($cobradas === 'solo-cobradas') {
            $query->where(function ($q) use ($estado_de_la_cuenta) {
                $q->whereNull('sales.client_id')
                  ->orWhere('sales.client_id', 0)
                  ->orWhere('sales.omitir_en_cuenta_corriente', 1)
                  ->orWhereRaw($estado_de_la_cuenta . " = 'pagado'");
            });
        } else if ($cobradas === 'solo-sin-cobrar') {
            /* `sale.client_id && sale.current_acount && status != 'pagado'`: sin fila de cuenta, no es "sin cobrar". */
            $query->whereNotNull('sales.client_id')
                  ->where('sales.client_id', '<>', 0)
                  ->whereRaw($estado_de_la_cuenta . " IS NOT NULL")
                  ->whereRaw($estado_de_la_cuenta . " <> 'pagado'");
        }

        /* Show option con / sin factura. `whereHas` respeta el soft delete de AfipTicket, igual que el eager load. */
        $afip = $request->query('afip_ticket_show_option', 'con-y-sin-factura');

        if ($afip === 'solo-con-factura') {
            $query->whereHas('afip_tickets');
        } else if ($afip === 'solo-sin-factura') {
            $query->whereDoesntHave('afip_tickets');
        }

        /* Show option metodo de pago. */
        $metodo_de_pago_id = self::metodo_de_pago_id($request);

        if (!is_null($metodo_de_pago_id)) {
            $query->where(function ($q) use ($metodo_de_pago_id, $consolidadas) {
                $q->whereHas('current_acount_payment_methods', function ($q2) use ($metodo_de_pago_id) {
                    /* Calificada: adentro del EXISTS hay un join con la pivot y `id` a secas es ambiguo. */
                    $q2->where('current_acount_payment_methods.id', $metodo_de_pago_id);
                });

                if ($consolidadas) {
                    $q->orWhere('sales.is_consolidacion_facturacion', 1);
                }
            });
        }

        return $query;
    }

    /**
     * Cantidad y los ocho totales del conjunto filtrado, en UNA consulta de agregados.
     *
     * Espejo exacto de `Total.vue`: pesos = `moneda_id = 1`, dolares = `moneda_id = 2` (una venta
     * con `moneda_id` NULL no suma en ninguno, igual que hoy en el navegador); total = `total`,
     * costos = `total_cost`, ganancia = `ganancia`, cuenta corriente = `total` de las ventas con
     * cliente y sin `omitir_en_cuenta_corriente`. Todo sale de columnas persistidas de `sales`.
     *
     * @param  \Illuminate\Database\Eloquent\Builder $query_filtrada
     * @param  Request $request
     * @return array
     */
    static function totales($query_filtrada, Request $request)
    {
        return self::totales_de_los_agregados(self::agregados($query_filtrada));
    }

    /**
     * La consulta de agregados en si: una fila con la cantidad, los ocho totales y, ademas, la
     * primera y la ultima `created_at` del conjunto.
     *
     * `toBase()` aplica los scopes globales (el soft delete de Sale) y devuelve el query builder
     * pelado, que es el unico que deja pisar el SELECT con agregados.
     *
     * El rango de `created_at` no es un total: viaja aca para no pagar una consulta mas sobre el
     * mismo conjunto. Lo usa `totales_sin_iva()` para acotar las subqueries de comprobantes (ver
     * ahi por que no se puede derivar del pedido).
     *
     * @param  \Illuminate\Database\Eloquent\Builder $query_filtrada
     * @return object  Fila de agregados (cantidad, pesos_*, dolares_*, primera_venta, ultima_venta).
     */
    static function agregados($query_filtrada)
    {
        $cuenta_corriente = 'sales.client_id IS NOT NULL AND sales.client_id <> 0'
            . ' AND (sales.omitir_en_cuenta_corriente IS NULL OR sales.omitir_en_cuenta_corriente = 0)';

        return (clone $query_filtrada)->toBase()->selectRaw(
            'COUNT(*) AS cantidad'
            . ', MIN(sales.created_at) AS primera_venta'
            . ', MAX(sales.created_at) AS ultima_venta'
            . ', SUM(CASE WHEN sales.moneda_id = 1 THEN sales.total ELSE 0 END) AS pesos_total'
            . ', SUM(CASE WHEN sales.moneda_id = 1 THEN sales.total_cost ELSE 0 END) AS pesos_costos'
            . ', SUM(CASE WHEN sales.moneda_id = 1 THEN sales.ganancia ELSE 0 END) AS pesos_ganancia'
            . ', SUM(CASE WHEN sales.moneda_id = 1 AND ' . $cuenta_corriente . ' THEN sales.total ELSE 0 END) AS pesos_cuenta_corriente'
            . ', SUM(CASE WHEN sales.moneda_id = 2 THEN sales.total ELSE 0 END) AS dolares_total'
            . ', SUM(CASE WHEN sales.moneda_id = 2 THEN sales.total_cost ELSE 0 END) AS dolares_costos'
            . ', SUM(CASE WHEN sales.moneda_id = 2 THEN sales.ganancia ELSE 0 END) AS dolares_ganancia'
            . ', SUM(CASE WHEN sales.moneda_id = 2 AND ' . $cuenta_corriente . ' THEN sales.total ELSE 0 END) AS dolares_cuenta_corriente'
        )->first();
    }

    /**
     * Arma el array de totales (la forma que lee la SPA) a partir de la fila de `agregados()`.
     *
     * @param  object $fila
     * @return array
     */
    static function totales_de_los_agregados($fila)
    {
        return [
            'cantidad' => (int) $fila->cantidad,
            'pesos'    => [
                'total'            => (float) $fila->pesos_total,
                'costos'           => (float) $fila->pesos_costos,
                'ganancia'         => (float) $fila->pesos_ganancia,
                'cuenta_corriente' => (float) $fila->pesos_cuenta_corriente,
            ],
            'dolares'  => [
                'total'            => (float) $fila->dolares_total,
                'costos'           => (float) $fila->dolares_costos,
                'ganancia'         => (float) $fila->dolares_ganancia,
                'cuenta_corriente' => (float) $fila->dolares_cuenta_corriente,
            ],
        ];
    }

    /**
     * Los totales en pesos SIN IVA del conjunto filtrado: cuanto de `total` y de `costos` es IVA, para
     * que el panel de Ventas pueda mostrar la misma cuenta que el Estado de Resultados.
     *
     * Devuelve tres claves, que se suman a `totales.pesos` y que la SPA trata como OPCIONALES:
     *
     *   - `total_sin_iva`: el `total` neto del IVA que cada venta declaro ante ARCA, con la MISMA
     *     expresion que suma el renglon "Ventas netas" del reporte
     *     (`IvaDeVentaHelper::expresion_total_neto_de_iva()`). Una venta sin comprobante no declara
     *     nada y entra entera; un comprobante autorizado sin `importe_iva` medido tambien entra
     *     entero (ver `ventas_con_iva_sin_medir`).
     *   - `costos_sin_iva`: `costos` menos el credito fiscal que trae adentro el costo, con el
     *     criterio de `CostoDeVentaHelper`. En una cuenta cuyo costo ya se guarda neto (la mayoria) el
     *     credito es 0 y da igual que `costos`.
     *   - `ventas_con_iva_sin_medir`: cuantas ventas del conjunto entraron enteras por lo de arriba.
     *
     * Con esto `total_sin_iva - costos_sin_iva` cierra con `ganancia` (que ya se persiste sin IVA)
     * para las ventas cuya ganancia se calculo con el criterio nuevo.
     *
     * Solo pesos: el IVA de ARCA se declara en pesos y no hay una conversion honesta para dolares.
     *
     * 🔴 POR QUE VAN EN CONSULTAS APARTE de `agregados()`:
     *
     *   1. Los joins de comprobantes (`aplicar_joins_de_iva`) suman `sales as venta_consolidacion`, que
     *      tiene sus propias `user_id`, `created_at`, `terminada`...; el builder del controller filtra
     *      por esas columnas SIN calificar (`where('user_id', ...)`, `enRangoDeFechas`), y con el
     *      join puesto serian ambiguas. Por eso el conjunto filtrado entra como `sales.id IN (...)`
     *      (mismo criterio que `total_del_metodo_de_pago`) y los joins van sobre una query propia.
     *   2. El costo neto necesita un join a `article_sale` (una fila por LINEA), que multiplica las
     *      filas de cada venta: mezclado con la de totales inflaria `total` y `ganancia`.
     *
     * Y a proposito NO se recorre el conjunto en PHP: el criterio de IVA se escribe una sola vez, en
     * los helpers, y aca solo se lo aplica en SQL igual que el reporte.
     *
     * 🔴 EL RANGO DE FECHAS DE LOS COMPROBANTES SALE DEL CONJUNTO, NO DEL PEDIDO. `aplicar_joins_de_iva`
     * necesita `$desde/$hasta` para acotar sus subqueries (sin eso agrupa `afip_tickets` entera). Pero
     * derivarlos de la ruta seria un bug silencioso: con la preferencia "fechar por dia de entrega"
     * el listado incluye ventas cuyo `created_at` cae FUERA del dia pedido, y esas quedarian medidas
     * en 0 de IVA (o sea, contadas como en negro). La primera y la ultima `created_at` del propio
     * conjunto (que ya trae `agregados()`) son un superconjunto exacto en cualquier modo, y tambien
     * cubren los modulos que no llevan fecha en la ruta.
     *
     * @param  \Illuminate\Database\Eloquent\Builder $query_filtrada
     * @param  int   $user_id  Dueño de las ventas (los helpers de IVA acotan por cliente).
     * @param  float $costos   `totales.pesos.costos`, del que se resta el credito fiscal.
     * @param  object $agregados Fila de `agregados()` (trae la primera y la ultima `created_at`).
     * @return array ['total_sin_iva' => float, 'costos_sin_iva' => float, 'ventas_con_iva_sin_medir' => int]
     */
    static function totales_sin_iva($query_filtrada, $user_id, $costos, $agregados)
    {
        if ((int) $agregados->cantidad === 0 || is_null($agregados->primera_venta)) {
            return [
                'total_sin_iva'            => 0.0,
                'costos_sin_iva'           => 0.0,
                'ventas_con_iva_sin_medir' => 0,
            ];
        }

        /* Solo ids: el conjunto filtrado entra como subselect para no chocar con los joins (ver arriba). */
        $ids_del_conjunto = (clone $query_filtrada)->toBase()->select('sales.id');

        /* Total sin IVA y ventas sin medir: una sola consulta, sobre los joins de comprobantes. */
        $query_iva = Sale::query()
                        ->whereIn('sales.id', $ids_del_conjunto)
                        ->where('sales.moneda_id', 1);

        IvaDeVentaHelper::aplicar_joins_de_iva($query_iva, $user_id, $agregados->primera_venta, $agregados->ultima_venta);

        $fila_iva = $query_iva->toBase()->selectRaw(
            'SUM(' . IvaDeVentaHelper::expresion_total_neto_de_iva() . ') AS total_sin_iva'
            . ', SUM(' . IvaDeVentaHelper::expresion_venta_con_iva_sin_medir() . ') AS ventas_con_iva_sin_medir'
        )->first();

        return [
            'total_sin_iva'            => round((float) $fila_iva->total_sin_iva, 2),
            'costos_sin_iva'           => round($costos - self::credito_fiscal_del_costo($ids_del_conjunto, $user_id), 2),
            'ventas_con_iva_sin_medir' => (int) $fila_iva->ventas_con_iva_sin_medir,
        ];
    }

    /**
     * IVA de compra (credito fiscal) que trae adentro el costo de las ventas en pesos del conjunto: lo
     * que hay que restarle a `sales.total_cost` para tener el costo neto.
     *
     * Se calcula como `SUM(costo bruto de la linea - costo neto de la linea)` con
     * `CostoDeVentaHelper::expresion_costo_neto_de_linea()`, la misma expresion que usa el costo de
     * mercaderia vendida del reporte. Se resta el CREDITO de `total_cost` y no se suma el neto de las
     * lineas a proposito: `total_cost` incluye tambien el costo de las promociones de vinoteca, que no
     * tienen alicuota propia; asi lo que no se puede atribuir queda como esta, igual que en
     * `sales.ganancia`.
     *
     * Si la cuenta guarda el costo neto (o es monotributista, que no recupera ese IVA) no hay credito
     * y no se hace ninguna consulta: `costos_sin_iva` es `costos`.
     *
     * @param  \Illuminate\Database\Query\Builder $ids_del_conjunto  Subselect de `sales.id`.
     * @param  int $user_id
     * @return float
     */
    static function credito_fiscal_del_costo($ids_del_conjunto, $user_id)
    {
        $user = CostoDeVentaHelper::user_por_id($user_id);

        if (!CostoDeVentaHelper::hay_credito_fiscal_en_el_costo($user)) {
            return 0.0;
        }

        $query = Sale::query()
                    ->join('article_sale', 'article_sale.sale_id', '=', 'sales.id')
                    ->whereIn('sales.id', $ids_del_conjunto)
                    ->where('sales.moneda_id', 1);

        /* Agrega los joins a `articles` e `ivas` (con alias propios) y devuelve la expresion del neto. */
        $costo_neto = CostoDeVentaHelper::expresion_costo_neto_de_linea($query, 'article_sale', $user);

        $fila = $query->toBase()->selectRaw(
            'SUM((article_sale.cost * article_sale.amount) - ' . $costo_neto . ') AS credito'
        )->first();

        return round((float) $fila->credito, 2);
    }

    /**
     * Sub-total del metodo de pago elegido sobre el conjunto filtrado, o null si no hay uno elegido.
     *
     * Se suma `amount` de la pivot `current_acount_payment_method_sale` con `sale_id IN (subquery)`
     * y NO con un join contra `sales`: la query base viene del controller con `where('user_id', ...)`
     * sin calificar, y en un join `user_id` seria ambigua. La subquery deja a cada tabla con su
     * propio alcance.
     *
     * @param  \Illuminate\Database\Eloquent\Builder $query_filtrada
     * @param  Request $request
     * @return array|null  ['id' => int, 'total' => float] o null
     */
    static function total_del_metodo_de_pago($query_filtrada, Request $request)
    {
        $metodo_de_pago_id = self::metodo_de_pago_id($request);

        if (is_null($metodo_de_pago_id)) {
            return null;
        }

        $ids_del_conjunto = (clone $query_filtrada)->toBase()->select('sales.id');

        $total = DB::table('current_acount_payment_method_sale')
                    ->where('current_acount_payment_method_id', $metodo_de_pago_id)
                    ->whereIn('sale_id', $ids_del_conjunto)
                    ->sum('amount');

        return [
            'id'    => $metodo_de_pago_id,
            'total' => (float) $total,
        ];
    }

    /**
     * Contadores "(N)" de las solapas de sucursal y de empleado, sobre el conjunto BASE.
     *
     * 🔴 Se calculan sobre la base y NO sobre el conjunto filtrado a proposito: `AddressNav.vue` y
     * `EmployeeNav.vue` cuentan sobre `this.sales` (el dia entero, sin solapas ni show options), asi
     * que el "(N)" de una sucursal no cambia al elegir "solo con factura" ni al pararse en otra
     * sucursal. Si se calcularan sobre lo filtrado, al pararse en la sucursal A todas las demas
     * solapas dirian (0).
     *
     * Vuelven como LISTAS y no como arrays indexados por id: un array PHP con claves enteras no
     * consecutivas se serializa como objeto JSON, y con claves 0..n como lista; la SPA no tiene por
     * que adivinar cual de las dos le llego.
     *
     * @param  \Illuminate\Database\Eloquent\Builder $query_base
     * @return array  ['por_sucursal' => [...], 'por_empleado' => [...], 'sin_empleado' => int]
     */
    static function conteos_por_solapa($query_base)
    {
        /* `sale.address_id && ...` en el front: NULL y 0 no cuentan para ninguna sucursal. */
        $por_sucursal = (clone $query_base)->toBase()
                            ->selectRaw('sales.address_id AS address_id, COUNT(*) AS cantidad')
                            ->whereNotNull('sales.address_id')
                            ->where('sales.address_id', '<>', 0)
                            ->groupBy('sales.address_id')
                            ->get()
                            ->map(function ($fila) {
                                return [
                                    'address_id' => (int) $fila->address_id,
                                    'cantidad'   => (int) $fila->cantidad,
                                ];
                            })
                            ->values()
                            ->all();

        /* `sale.employee_id && ...` en el front: NULL y 0 van a la solapa del dueño (sin_empleado). */
        $por_empleado = (clone $query_base)->toBase()
                            ->selectRaw('sales.employee_id AS employee_id, COUNT(*) AS cantidad')
                            ->whereNotNull('sales.employee_id')
                            ->where('sales.employee_id', '<>', 0)
                            ->groupBy('sales.employee_id')
                            ->get()
                            ->map(function ($fila) {
                                return [
                                    'employee_id' => (int) $fila->employee_id,
                                    'cantidad'    => (int) $fila->cantidad,
                                ];
                            })
                            ->values()
                            ->all();

        $sin_empleado = (clone $query_base)->toBase()
                            ->where(function ($q) {
                                $q->whereNull('sales.employee_id')
                                  ->orWhere('sales.employee_id', 0);
                            })
                            ->count();

        return [
            'por_sucursal' => $por_sucursal,
            'por_empleado' => $por_empleado,
            'sin_empleado' => (int) $sin_empleado,
        ];
    }

    /**
     * Las filas de la pagina pedida, con el mismo `withAll()` e imagenes por preferencia que el
     * listado sin paginar.
     *
     * 🔴 El `orderBy('sales.id', 'DESC')` de desempate NO es opcional. Las ventas de un mismo
     * segundo comparten `created_at` (una importacion, una consolidacion, dos cajas a la vez), y
     * con `ORDER BY created_at DESC` solo, MySQL no garantiza el mismo orden entre dos consultas
     * con distinto OFFSET: una venta puede aparecer en la pagina 1 y de nuevo en la 2, y otra no
     * aparecer en ninguna. Con la PK de desempate el orden es total y las paginas no se pisan.
     * Hay un test que se pone rojo si se saca (20_Listado_De_Ventas_Paginado_Test).
     *
     * @param  \Illuminate\Database\Eloquent\Builder $query_filtrada
     * @param  int $page
     * @param  int $per_page
     * @param  int $user_id
     * @return \Illuminate\Database\Eloquent\Collection
     */
    static function filas_de_la_pagina($query_filtrada, $page, $per_page, $user_id)
    {
        $query = (clone $query_filtrada)
                    ->withAll()
                    ->orderBy('sales.created_at', 'DESC')
                    ->orderBy('sales.id', 'DESC');

        SaleArticlesEagerLoadHelper::apply_images_if_preferred($query, $user_id);

        return $query->forPage($page, $per_page)->get();
    }

    /**
     * @param  Request $request
     * @return bool
     */
    static function mostrar_consolidadas(Request $request)
    {
        return self::bandera($request, 'mostrar_consolidadas');
    }

    /**
     * Lee una bandera 0/1 de la query string aceptando tambien `true`/`false`: en el store de la SPA
     * `mostrar_consolidadas` y `only_owner` son booleanos, y si viajan sin convertir axios los
     * serializa como "true"/"false", que un `(int)` leeria como 0.
     *
     * @param  Request $request
     * @param  string  $nombre
     * @return bool
     */
    static function bandera(Request $request, $nombre)
    {
        return filter_var($request->query($nombre, 0), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Id del metodo de pago elegido en la show option, o null si es `todos` (o no vino, o no es un id).
     *
     * @param  Request $request
     * @return int|null
     */
    static function metodo_de_pago_id(Request $request)
    {
        $valor = $request->query('payment_method_show_option', 'todos');

        if (is_null($valor) || $valor === '' || strtolower((string) $valor) === 'todos') {
            return null;
        }

        $id = (int) $valor;

        return $id > 0 ? $id : null;
    }
}
