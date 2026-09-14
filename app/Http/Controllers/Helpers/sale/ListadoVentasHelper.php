<?php

namespace App\Http\Controllers\Helpers\sale;

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
     * Del builder base salen CINCO consultas, y cada una parte de un `clone` propio: el Builder de
     * Eloquent implementa `__clone` clonando el query builder de abajo, asi que los `where` que le
     * agrega una consulta no se le pegan a la siguiente. Sin el clone, la de conteos por solapa
     * arrastraria las show options de la de totales y las solapas cambiarian al tocar un filtro.
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

        $totales = self::totales($filtrada, $request);

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

        /* Show option cobradas / sin cobrar (espejo de `venta_cobrada` de mixins/generals.js). */
        $cobradas = $request->query('ventas_cobradas_show_option', 'cobradas-y-no-cobradas');

        if ($cobradas === 'solo-cobradas') {
            $query->where(function ($q) {
                $q->whereNull('sales.client_id')
                  ->orWhere('sales.client_id', 0)
                  ->orWhere('sales.omitir_en_cuenta_corriente', 1)
                  ->orWhereHas('current_acounts', function ($q2) {
                      $q2->where('current_acounts.status', 'pagado');
                  });
            });
        } else if ($cobradas === 'solo-sin-cobrar') {
            $query->whereNotNull('sales.client_id')
                  ->where('sales.client_id', '<>', 0)
                  ->whereHas('current_acounts', function ($q2) {
                      $q2->where('current_acounts.status', '<>', 'pagado');
                  });
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
     * `toBase()` aplica los scopes globales (el soft delete de Sale) y devuelve el query builder
     * pelado, que es el unico que deja pisar el SELECT con agregados.
     *
     * @param  \Illuminate\Database\Eloquent\Builder $query_filtrada
     * @param  Request $request
     * @return array
     */
    static function totales($query_filtrada, Request $request)
    {
        $cuenta_corriente = 'sales.client_id IS NOT NULL AND sales.client_id <> 0'
            . ' AND (sales.omitir_en_cuenta_corriente IS NULL OR sales.omitir_en_cuenta_corriente = 0)';

        $fila = (clone $query_filtrada)->toBase()->selectRaw(
            'COUNT(*) AS cantidad'
            . ', SUM(CASE WHEN sales.moneda_id = 1 THEN sales.total ELSE 0 END) AS pesos_total'
            . ', SUM(CASE WHEN sales.moneda_id = 1 THEN sales.total_cost ELSE 0 END) AS pesos_costos'
            . ', SUM(CASE WHEN sales.moneda_id = 1 THEN sales.ganancia ELSE 0 END) AS pesos_ganancia'
            . ', SUM(CASE WHEN sales.moneda_id = 1 AND ' . $cuenta_corriente . ' THEN sales.total ELSE 0 END) AS pesos_cuenta_corriente'
            . ', SUM(CASE WHEN sales.moneda_id = 2 THEN sales.total ELSE 0 END) AS dolares_total'
            . ', SUM(CASE WHEN sales.moneda_id = 2 THEN sales.total_cost ELSE 0 END) AS dolares_costos'
            . ', SUM(CASE WHEN sales.moneda_id = 2 THEN sales.ganancia ELSE 0 END) AS dolares_ganancia'
            . ', SUM(CASE WHEN sales.moneda_id = 2 AND ' . $cuenta_corriente . ' THEN sales.total ELSE 0 END) AS dolares_cuenta_corriente'
        )->first();

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
