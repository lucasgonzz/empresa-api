<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\puntos\PuntosConfigHelper;
use App\Http\Controllers\Helpers\puntos\PuntosSaldoHelper;
use App\Models\Client;
use App\Models\MovimientoPunto;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Lectura del módulo de puntos y el único punto de escritura manual (el ajuste).
 *
 *   GET  api/puntos/cliente/{client_id}     -> la ficha: saldo, lo que vence y el libro paginado
 *   GET  api/puntos/disponible/{client_id}  -> lo que VENDER pide al elegir un cliente
 *   POST api/puntos/ajuste                  -> ajuste manual, en más o en menos
 *   GET  api/puntos/reporte                 -> el pasivo del programa en un período
 *   GET  api/puntos/reporte/detalle         -> el drill-down paginado de una de esas tarjetas
 *
 * -------------------------------------------------------------------------------------------
 * 🔴 LAS RESPUESTAS VAN PLANAS, SIN CLAVE ENVOLVENTE
 * -------------------------------------------------------------------------------------------
 *
 * `src/store/puntos.js` de la SPA lee `res.data.saldo`, `res.data.valor_punto`,
 * `res.data.movimientos`, no `res.data.puntos.saldo`. No es una preferencia de estilo: el store
 * ya está escrito así y envolver el cuerpo rompe dos pantallas. El único endpoint de este módulo
 * que sí usa la forma envolvente del repo es el ABM (`SistemaDePuntosController`), porque ahí el
 * que lee es `__base_store`, que espera `models` / `model`.
 *
 * -------------------------------------------------------------------------------------------
 * 🔴 EL SALDO NO SE CALCULA ACÁ. SE PIDE.
 * -------------------------------------------------------------------------------------------
 *
 * Los tres lugares que muestran saldo (VENDER, la ficha y el reporte) lo sacan de
 * `PuntosSaldoHelper::saldo()`, que es `SUM(movimiento_puntos.puntos)` y nada más. Si este
 * controller le agregara su propia exclusión de vencidos o de revertidos, el mismo invariante
 * quedaría decidido con dos criterios distintos y la primera pantalla que se olvide uno muestra
 * otro número (APRENDER_NO_PARCHEAR.md:997). Los vencidos y los revertidos ya son filas
 * negativas del libro.
 *
 * -------------------------------------------------------------------------------------------
 * 🔴 TODA QUERY FILTRA POR user_id, Y EL client_id SE VERIFICA ANTES DE DEVOLVER NADA
 * -------------------------------------------------------------------------------------------
 *
 * El middleware `check_extencion_empresa:puntos_clientes` corta al comercio que no compró el
 * módulo, pero no dice nada sobre de quién es el cliente que se pidió: un `client_id` de otro
 * comercio tiene que dar 404, no el saldo de un desconocido.
 *
 * PHP 7.4 estricto: ninguna sintaxis de PHP 8 en ningún camino de este archivo.
 */
class PuntoController extends Controller
{
    /** Movimientos por página en la ficha del cliente. Es el default de `src/store/puntos.js`. */
    const PER_PAGE_FICHA = 25;

    /** Registros por página en el drill-down del reporte, igual que `ReporteDetalleController`. */
    const PER_PAGE_DETALLE = 50;

    /**
     * Tope duro de `per_page`, mismo criterio que `ReporteDetalleController::PER_PAGE_MAX`: un
     * comercio con años de movimientos puede pedir un rango anual y sin tope eso mata al server.
     */
    const PER_PAGE_MAX = 500;

    /**
     * Los únicos `tipo` que existen en el libro. Lista blanca dura, igual que el `switch` de
     * `ReporteDetalleController`: un tipo no reconocido devuelve 422, nunca una lista vacía que
     * se lea como "no hubo movimientos".
     */
    const TIPOS = ['ganados', 'canjeados', 'vencidos', 'revertidos', 'ajuste'];

    /**
     * Los tipos que en el libro son siempre NEGATIVOS. El reporte los muestra en magnitud
     * ("se canjearon 300 puntos"), no con el signo con el que están guardados.
     */
    const TIPOS_NEGATIVOS = ['canjeados', 'vencidos', 'revertidos'];

    /** Ventana del "por vencer" de la ficha, en días. */
    const DIAS_POR_VENCER = 90;

    const MENSAJE_CLIENTE_NO_ENCONTRADO = 'No se encontró el cliente.';

    const MENSAJE_SIN_PROGRAMA = 'El sistema de puntos no está activo.';

    /**
     * GET api/puntos/cliente/{client_id}
     *
     * La ficha del cliente: el saldo, lo que le vence en los próximos 90 días y el libro de
     * movimientos paginado, del más nuevo al más viejo.
     *
     * @param  \Illuminate\Http\Request  $request  `page`, `per_page` opcionales.
     * @param  int                       $client_id
     * @return \Illuminate\Http\JsonResponse
     */
    public function cliente(Request $request, $client_id)
    {
        $user_id = $this->userId();

        if (is_null($this->cliente_del_comercio($user_id, $client_id))) {
            return response()->json(['message' => self::MENSAJE_CLIENTE_NO_ENCONTRADO], 404);
        }

        $page = max(1, (int) $request->input('page', 1));
        $per_page = $this->per_page_del_request($request, self::PER_PAGE_FICHA);

        $total_registros = (int) MovimientoPunto::where('user_id', $user_id)
                                                ->where('client_id', $client_id)
                                                ->count();

        /*
         * El desempate por `id` no es decorativo: dos lotes de la misma venta mixta se escriben
         * en el mismo segundo, y sin él la página 2 puede repetir una fila de la página 1.
         */
        /*
         * `with('sale', 'price_type')` y NO `withAll()`: el scope del modelo trae también
         * `client`, y acá todas las filas son del MISMO cliente, que el que llama ya pidió por
         * id. Con 25 movimientos eso son 25 copias del cliente entero en la respuesta, para una
         * pantalla que no muestra ninguna columna de cliente.
         */
        $movimientos = MovimientoPunto::where('user_id', $user_id)
                                        ->where('client_id', $client_id)
                                        ->with('sale', 'price_type')
                                        ->orderBy('created_at', 'DESC')
                                        ->orderBy('id', 'DESC')
                                        ->skip(($page - 1) * $per_page)
                                        ->take($per_page)
                                        ->get();

        $saldo = PuntosSaldoHelper::saldo($user_id, $client_id);

        $sistema = PuntosConfigHelper::programa_activo($user_id);

        $valor_punto = is_null($sistema) ? 0 : (float) $sistema->valor_punto;

        return response()->json([
            'saldo'              => $saldo,
            'por_vencer_90_dias' => PuntosSaldoHelper::puntos_por_vencer($user_id, $client_id, self::DIAS_POR_VENCER),
            'valor_punto'        => $valor_punto,
            'equivalencia_pesos' => round($saldo * $valor_punto, 2),
            'movimientos'        => $movimientos,
            'paginacion'         => [
                'page'            => $page,
                'per_page'        => $per_page,
                'total_registros' => $total_registros,
            ],
        ], 200);
    }

    /**
     * GET api/puntos/disponible/{client_id}
     *
     * Lo que VENDER necesita para armar el panel de canje al elegir un cliente.
     *
     * 🔴 Liviano a propósito: una sola suma y la configuración, que ya viene memoizada. Corre en
     * CADA cambio de cliente de la venta, así que no puede traerse el libro de movimientos ni
     * calcular el "por vencer" (que sí recorre lotes).
     *
     * Devuelve 200 con todo en cero cuando no hay programa activo, y no un 403: el comercio
     * compró la extensión (si no, no habría pasado el middleware) y todavía no configuró el
     * programa. Que el módulo esté prendido y sin configurar no es un error del vendedor, y un
     * 4xx acá le abriría el modal de error global de la SPA (`src/main.js:152`) en el medio de
     * una venta.
     *
     * @param  int  $client_id
     * @return \Illuminate\Http\JsonResponse
     */
    public function disponible($client_id)
    {
        $user_id = $this->userId();

        if (is_null($this->cliente_del_comercio($user_id, $client_id))) {
            return response()->json(['message' => self::MENSAJE_CLIENTE_NO_ENCONTRADO], 404);
        }

        $sistema = PuntosConfigHelper::programa_activo($user_id);

        if (is_null($sistema)) {
            return response()->json([
                'saldo'              => 0,
                'valor_punto'        => 0,
                'minimo_canje'       => 0,
                'tope_porcentaje'    => 0,
                'equivalencia_pesos' => 0,
                'puede_canjear'      => false,
            ], 200);
        }

        $saldo = PuntosSaldoHelper::saldo($user_id, $client_id);

        $minimo_canje = (float) $sistema->minimo_canje;

        return response()->json([
            'saldo'              => $saldo,
            'valor_punto'        => (float) $sistema->valor_punto,
            'minimo_canje'       => $minimo_canje,
            'tope_porcentaje'    => (float) $sistema->tope_porcentaje,
            'equivalencia_pesos' => round($saldo * (float) $sistema->valor_punto, 2),
            /*
             * La API decide si el cliente puede canjear y la SPA no repite la cuenta: es el
             * mismo invariante y no puede estar escrito en dos lados.
             */
            'puede_canjear'      => $saldo > 0 && $saldo >= $minimo_canje,
        ], 200);
    }

    /**
     * POST api/puntos/ajuste
     *
     * Ajuste manual de puntos. `puntos` viaja SIGNADO: positivo abre un lote nuevo, negativo
     * consume los lotes más viejos igual que un canje.
     *
     * 🔴 El ajuste positivo es un LOTE, no una fila suelta. Si no lo fuera, un cliente cuyo saldo
     * entero viniera de ajustes tendría saldo mayor a cero y nada de donde consumir al canjear:
     * el canje escribiría su movimiento negativo sin una sola fila de consumo y el vencimiento no
     * sabría qué mirar. Por eso `PuntosSaldoHelper::TIPOS_LOTE` incluye 'ajuste'.
     *
     * @param  \Illuminate\Http\Request  $request  `client_id`, `puntos` (signado), `detalle`.
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajuste(Request $request)
    {
        $user_id = $this->userId();

        $sistema = PuntosConfigHelper::programa_activo($user_id);

        if (is_null($sistema)) {
            return response()->json(['message' => self::MENSAJE_SIN_PROGRAMA], 422);
        }

        $client = $this->cliente_del_comercio($user_id, $request->client_id);

        if (is_null($client)) {
            return response()->json(['message' => self::MENSAJE_CLIENTE_NO_ENCONTRADO], 404);
        }

        $puntos = round((float) $request->puntos, 2);

        if (abs($puntos) <= PuntosSaldoHelper::DELTA) {
            return response()->json(['message' => 'El ajuste tiene que ser distinto de cero.'], 422);
        }

        $detalle = trim((string) $request->detalle);

        if ($detalle === '') {
            return response()->json(['message' => 'Poné un motivo para el ajuste.'], 422);
        }

        $saldo_previo = PuntosSaldoHelper::saldo($user_id, $client->id);

        /*
         * 🔴 Un ajuste negativo no puede dejar el saldo en rojo. La reversión de una venta
         * anulada SÍ puede (y es a propósito: el negocio no puede pagar dos veces el descuento
         * que el cliente ya se llevó), pero eso es una consecuencia automática de un hecho
         * económico. Un ajuste es alguien tipeando un número a mano, y ahí un 422 con el saldo
         * real adentro es mucho mejor que un saldo negativo que después nadie sabe explicar.
         */
        if ($puntos < 0 && abs($puntos) > $saldo_previo + PuntosSaldoHelper::DELTA) {
            return response()->json([
                'message' => 'El cliente tiene '.$saldo_previo.' puntos: no se le pueden restar '.abs($puntos).'.',
            ], 422);
        }

        $movimiento = null;

        DB::transaction(function () use ($user_id, $client, $sistema, $puntos, $detalle, &$movimiento) {

            $movimiento = MovimientoPunto::create([
                'user_id'              => $user_id,
                'client_id'            => $client->id,
                'sistema_de_puntos_id' => $sistema->id,
                'tipo'                 => 'ajuste',
                'puntos'               => $puntos,
                'sale_id'              => null,
                /*
                 * 0 es el centinela "sin lista de precio", igual que en los movimientos de una
                 * venta sin lista. La columna es NOT NULL a propósito: MySQL deja pasar N filas
                 * con NULL en un unique y el unique del libro dejaría de servir.
                 */
                'price_type_id'        => 0,
                'monto_base'           => null,
                'detalle'              => $detalle,
                'vence_at'             => $puntos > 0 ? $this->vence_at_del_programa($sistema) : null,
                'consumido'            => 0,
                'anulado_at'           => null,
                /*
                 * Quién lo hizo: el usuario autenticado de verdad, no el owner. `userId(false)`
                 * devuelve el empleado cuando el que está adentro es un empleado.
                 */
                'employee_id'          => $this->userId(false),
            ]);

            if ($puntos < 0) {
                PuntosSaldoHelper::consumir_fifo($movimiento, abs($puntos));
            }
        });

        return response()->json([
            'movimiento' => $movimiento,
            'saldo'      => PuntosSaldoHelper::saldo($user_id, $client->id),
        ], 201);
    }

    /**
     * GET api/puntos/reporte?desde=&hasta=
     *
     * El pasivo del programa: cuántos puntos se emitieron, se canjearon, vencieron, se
     * revirtieron y se ajustaron en el período, y cuánto saldo queda vivo.
     *
     * 🔴 `saldo_vivo` NO respeta `desde`: es un STOCK, no un flujo. Es el saldo acumulado de todo
     * el libro hasta `hasta` inclusive, que es el número que le importa al comercio ("¿cuánto le
     * debo hoy a mis clientes?"). Los otros cinco sí son flujos del período. Mezclarlos sería
     * mostrar en la misma pantalla dos cosas que se leen igual y significan distinto.
     *
     * @param  \Illuminate\Http\Request  $request  `desde`, `hasta` en Y-m-d, ambos inclusive.
     * @return \Illuminate\Http\JsonResponse
     */
    public function reporte(Request $request)
    {
        $user_id = $this->userId();

        $desde = $request->input('desde');
        $hasta = $request->input('hasta');

        $sistema = PuntosConfigHelper::programa_activo($user_id);

        $valor_punto = is_null($sistema) ? 0 : (float) $sistema->valor_punto;

        $cuerpo = [
            'desde'       => $desde,
            'hasta'       => $hasta,
            'valor_punto' => $valor_punto,
        ];

        foreach (self::TIPOS as $tipo) {
            $cuerpo[$this->clave_del_reporte($tipo)] = $this->tarjeta(
                $this->puntos_del_periodo($user_id, $tipo, $desde, $hasta),
                $valor_punto
            );
        }

        $cuerpo['saldo_vivo'] = $this->tarjeta($this->saldo_vivo($user_id, $hasta), $valor_punto);

        return response()->json($cuerpo, 200);
    }

    /**
     * GET api/puntos/reporte/detalle?desde=&hasta=&tipo=&page=
     *
     * El drill-down de una de las tarjetas del reporte: los movimientos de ese tipo, uno por uno,
     * paginados EN SQL.
     *
     * 🔴 La forma de la respuesta es la de `ReporteDetalleController.php:228` —`total`,
     * `registros` y la paginación ANIDADA en `paginacion`—, no la plana. Es el contrato que ya
     * consume la SPA para todos los drill-down de reportes, y esta pantalla vive al lado de esos.
     * `total` es el IMPORTE en pesos, igual que allá; los puntos van aparte en `total_puntos`,
     * porque acá la tarjeta muestra los dos números.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function reporte_detalle(Request $request)
    {
        $user_id = $this->userId();

        $tipo = $request->input('tipo');

        if (!in_array($tipo, self::TIPOS, true)) {
            return response()->json([
                'message' => 'Tipo de movimiento no reconocido: '.$tipo,
            ], 422);
        }

        $desde = $request->input('desde');
        $hasta = $request->input('hasta');

        $page = max(1, (int) $request->input('page', 1));
        $per_page = $this->per_page_del_request($request, self::PER_PAGE_DETALLE);

        $sistema = PuntosConfigHelper::programa_activo($user_id);

        $valor_punto = is_null($sistema) ? 0 : (float) $sistema->valor_punto;

        $total_puntos = $this->puntos_del_periodo($user_id, $tipo, $desde, $hasta);

        $total_registros = (int) $this->query_del_periodo($user_id, $tipo, $desde, $hasta)->count();

        /*
         * El listado se arma con el query builder y no con Eloquent + `withAll()`: son cinco
         * columnas de tres tablas y el LIMIT tiene que resolverlo la base. Un `get()` de
         * modelos con relaciones cargadas sobre un rango anual se trae el año entero a memoria
         * para después quedarse con 50 filas.
         */
        $registros = $this->query_del_periodo($user_id, $tipo, $desde, $hasta)
                            ->leftJoin('clients', 'clients.id', '=', 'movimiento_puntos.client_id')
                            ->leftJoin('sales', 'sales.id', '=', 'movimiento_puntos.sale_id')
                            ->leftJoin('price_types', 'price_types.id', '=', 'movimiento_puntos.price_type_id')
                            ->orderBy('movimiento_puntos.created_at', 'DESC')
                            ->orderBy('movimiento_puntos.id', 'DESC')
                            ->skip(($page - 1) * $per_page)
                            ->take($per_page)
                            ->get([
                                'movimiento_puntos.id',
                                'movimiento_puntos.created_at as fecha',
                                'movimiento_puntos.tipo',
                                'movimiento_puntos.detalle',
                                'movimiento_puntos.puntos',
                                'movimiento_puntos.monto_base',
                                'movimiento_puntos.vence_at',
                                'movimiento_puntos.client_id',
                                'clients.name as cliente',
                                'movimiento_puntos.sale_id',
                                'sales.num as sale_num',
                                'movimiento_puntos.price_type_id',
                                'price_types.name as price_type',
                            ]);

        foreach ($registros as $registro) {
            $registro->puntos = (float) $registro->puntos;
            $registro->monto_base = is_null($registro->monto_base) ? null : (float) $registro->monto_base;
            $registro->pesos = round($registro->puntos * $valor_punto, 2);
        }

        return response()->json([
            'tipo'         => $tipo,
            'total'        => round($total_puntos * $valor_punto, 2),
            'total_puntos' => $total_puntos,
            'registros'    => $registros,
            'paginacion'   => [
                'page'            => $page,
                'per_page'        => $per_page,
                'total_registros' => $total_registros,
            ],
        ], 200);
    }

    /*
     * ---------------------------------------------------------------------------------------
     *  Privados
     * ---------------------------------------------------------------------------------------
     */

    /**
     * El cliente pedido, si es de este comercio.
     *
     * @param  int  $user_id
     * @param  int  $client_id
     * @return \App\Models\Client|null
     */
    private function cliente_del_comercio($user_id, $client_id)
    {
        if (is_null($client_id) || $client_id === '') {
            return null;
        }

        return Client::where('id', $client_id)
                        ->where('user_id', $user_id)
                        ->first();
    }

    /**
     * La query base del reporte: los movimientos de un tipo en el período.
     *
     * @param  int          $user_id
     * @param  string       $tipo
     * @param  string|null  $desde
     * @param  string|null  $hasta
     * @return \Illuminate\Database\Query\Builder
     */
    private function query_del_periodo($user_id, $tipo, $desde, $hasta)
    {
        $query = DB::table('movimiento_puntos')
                    ->where('movimiento_puntos.user_id', $user_id)
                    ->where('movimiento_puntos.tipo', $tipo);

        if (!is_null($desde) && $desde !== '') {
            $query->where('movimiento_puntos.created_at', '>=', Carbon::parse($desde)->startOfDay());
        }

        if (!is_null($hasta) && $hasta !== '') {
            $query->where('movimiento_puntos.created_at', '<=', Carbon::parse($hasta)->endOfDay());
        }

        return $query;
    }

    /**
     * Los puntos de un tipo en el período, en MAGNITUD.
     *
     * 🔴 'canjeados', 'vencidos' y 'revertidos' están guardados en negativo (el saldo es
     * `SUM(puntos)` y no puede tener dos criterios), pero la tarjeta dice "se canjearon 300
     * puntos", no "-300". El signo se saca acá y en un solo lado. 'ajuste' es el único tipo que
     * puede ir para los dos lados, así que va con el signo neto tal cual, que es su significado.
     *
     * @param  int          $user_id
     * @param  string       $tipo
     * @param  string|null  $desde
     * @param  string|null  $hasta
     * @return float
     */
    private function puntos_del_periodo($user_id, $tipo, $desde, $hasta)
    {
        $suma = (float) $this->query_del_periodo($user_id, $tipo, $desde, $hasta)->sum('movimiento_puntos.puntos');

        if (in_array($tipo, self::TIPOS_NEGATIVOS, true)) {
            $suma = abs($suma);
        }

        return round($suma, 2);
    }

    /**
     * El saldo vivo de TODO el comercio hasta `hasta` inclusive. El pasivo del programa.
     *
     * @param  int          $user_id
     * @param  string|null  $hasta
     * @return float
     */
    private function saldo_vivo($user_id, $hasta)
    {
        $query = DB::table('movimiento_puntos')->where('user_id', $user_id);

        if (!is_null($hasta) && $hasta !== '') {
            $query->where('created_at', '<=', Carbon::parse($hasta)->endOfDay());
        }

        return round((float) $query->sum('puntos'), 2);
    }

    /**
     * Una tarjeta del reporte: el mismo número en puntos y en pesos.
     *
     * @param  float  $puntos
     * @param  float  $valor_punto
     * @return array
     */
    private function tarjeta($puntos, $valor_punto)
    {
        return [
            'puntos' => round($puntos, 2),
            'pesos'  => round($puntos * $valor_punto, 2),
        ];
    }

    /**
     * La clave con la que cada tipo aparece en el cuerpo del reporte.
     *
     * 'ganados' se llama 'emitidos' de cara al usuario (es la palabra de un programa de puntos)
     * y 'ajuste' va en plural porque es una tarjeta, no una fila. Los otros tres coinciden.
     *
     * @param  string  $tipo
     * @return string
     */
    private function clave_del_reporte($tipo)
    {
        if ($tipo === 'ganados') {
            return 'emitidos';
        }

        if ($tipo === 'ajuste') {
            return 'ajustes';
        }

        return $tipo;
    }

    /**
     * Cuándo vence un lote nuevo según la configuración del programa, o null si no vence.
     *
     * @param  \App\Models\SistemaDePuntos  $sistema
     * @return \Carbon\Carbon|null
     */
    private function vence_at_del_programa($sistema)
    {
        if (is_null($sistema->vencimiento_meses)) {
            return null;
        }

        return Carbon::now()->addMonths((int) $sistema->vencimiento_meses);
    }

    /**
     * @param  \Illuminate\Http\Request  $request
     * @param  int                       $default
     * @return int
     */
    private function per_page_del_request(Request $request, $default)
    {
        return min(self::PER_PAGE_MAX, max(1, (int) $request->input('per_page', $default)));
    }
}
