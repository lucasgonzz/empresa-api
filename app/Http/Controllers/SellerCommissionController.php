<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\Helpers\Numbers;
use App\Http\Controllers\Helpers\comisiones\ComisionesHelper;
use App\Http\Controllers\Helpers\comisiones\PagoVendedorHelper;
use App\Http\Controllers\Helpers\comisiones\PanelComisionesHelper;
use App\Models\SellerCommission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SellerCommissionController extends Controller
{

    /**
     * Grupo 268 · Prompt 03 — reemplaza el index viejo (solo `active`, sin moneda, sin
     * `user_id`, ordenado por `created_at`): separa comisiones liquidadas ('active', dentro del
     * rango de fechas) de pendientes ('inactive', SIN filtro de fecha — una comision pendiente
     * de hace meses sigue siendo plata a cobrar, no un dato historico para esconder).
     *
     * Ambas listas filtran por `moneda_id` (null tratado como 1, mismo criterio que
     * ComisionesHelper::recalcular_saldos) y por `user_id` (filtro que antes faltaba).
     *
     * @param int $model_id 0 = todos los vendedores.
     * @param int $moneda_id
     * @param string $from_date
     * @param string|null $until_date
     * @return \Illuminate\Http\JsonResponse
     */
    function index($model_id, $moneda_id, $from_date, $until_date = null) {

        if (is_null($moneda_id) || $moneda_id == 0) {
            $moneda_id = 1;
        }

        $filtro_moneda = function ($q) use ($moneda_id) {
            if ($moneda_id == 1) {
                $q->where('moneda_id', 1)->orWhereNull('moneda_id');
            } else {
                $q->where('moneda_id', $moneda_id);
            }
        };

        $liquidadas = SellerCommission::whereDate('created_at', '>=', $from_date)
                            ->withAll()
                            ->where('status', 'active')
                            ->where('user_id', $this->userId())
                            ->where($filtro_moneda)
                            ->orderBy('id', 'ASC');

        if (!is_null($until_date)) {
            $liquidadas = $liquidadas->whereDate('created_at', '<=', $until_date);
        }

        if ($model_id != 0) {
            $liquidadas = $liquidadas->where('seller_id', $model_id);
        }

        $liquidadas = $liquidadas->get();

        $pendientes = SellerCommission::withAll()
                            ->where('status', 'inactive')
                            ->where('user_id', $this->userId())
                            ->where($filtro_moneda)
                            ->orderBy('id', 'ASC');

        if ($model_id != 0) {
            $pendientes = $pendientes->where('seller_id', $model_id);
        }

        $pendientes = $pendientes->get();

        // El saldo hoy es el de la ULTIMA fila liquidada (ya lo mantiene actualizado
        // ComisionesHelper::recalcular_saldos en cada mutacion del ledger).
        $saldo = count($liquidadas) >= 1 ? (float)$liquidadas[count($liquidadas) - 1]->saldo : 0;

        // Total liquidado: bruto ganado (suma de los debe de las comisiones ya liquidadas), no
        // neteado contra los pagos ya hechos - eso es justamente lo que expresa "saldo".
        $total_liquidado = 0;
        foreach ($liquidadas as $liquidada) {
            if (!is_null($liquidada->debe)) {
                $total_liquidado += (float)$liquidada->debe;
            }
        }

        $total_pendiente = 0;
        foreach ($pendientes as $pendiente) {
            if (!is_null($pendiente->debe)) {
                $total_pendiente += (float)$pendiente->debe;
            }
        }

        return response()->json([
            'liquidadas' => $liquidadas,
            'pendientes' => $pendientes,
            'totales' => [
                'total_liquidado' => Numbers::redondear($total_liquidado),
                'total_pendiente' => Numbers::redondear($total_pendiente),
                'saldo'           => Numbers::redondear($saldo),
            ],
        ], 200);
    }

    /**
     * Alias de la ruta vieja (3 segmentos, sin moneda_id): asume moneda_id = 1, para no romper
     * nada que la siga llamando mientras el SPA se actualiza. Ver routes/api.php: esta ruta tiene
     * que quedar registrada ANTES que la nueva de 4 segmentos, porque con `{until_date?}`
     * opcional la nueva tambien podria matchear una URL de 3 segmentos y pisar este alias.
     *
     * Devuelve la forma VIEJA de la respuesta (`{models: [...]}`, solo liquidadas, sin totales):
     * el SPA sin actualizar lee `res.data.models`, no `res.data.liquidadas` — devolverle la forma
     * nueva bajo la URL vieja lo dejaria con la tabla vacia y sin ningun error visible.
     *
     * @param int $model_id
     * @param string $from_date
     * @param string|null $until_date
     * @return \Illuminate\Http\JsonResponse
     */
    function indexLegacy($model_id, $from_date, $until_date = null) {
        $nueva = $this->index($model_id, 1, $from_date, $until_date);
        $data = json_decode($nueva->getContent(), true);
        return response()->json(['models' => $data['liquidadas']], 200);
    }

    /**
     * Panel de comisiones del vendedor (mision comisiones-vendedor-tablas, 24/9/2026): las tres
     * tarjetas del modal. Query params opcionales: `desde`, `hasta` (Y-m-d; si vienen mal se
     * ignoran). Toda la logica en PanelComisionesHelper::resumen().
     *
     * GET seller-commission-panel/{seller_id}/{moneda_id}/resumen
     * Respuesta: {totales: {saldo, total_pendiente, total_pagado}, rango: {desde, hasta}}
     *
     * @param \Illuminate\Http\Request $request
     * @param int $seller_id
     * @param int $moneda_id
     * @return \Illuminate\Http\JsonResponse
     */
    function panelResumen(Request $request, $seller_id, $moneda_id) {

        $resumen = PanelComisionesHelper::resumen(
            $this->userId(),
            $seller_id,
            $moneda_id,
            $request->query('desde'),
            $request->query('hasta')
        );

        return response()->json($resumen, 200);
    }

    /**
     * Panel de comisiones del vendedor: tabla de liquidadas (ledger con comisiones, pagos y saldo
     * inicial), paginada de a 15, cada fila con `saldo_calculado`. Query params opcionales:
     * `desde`, `hasta`, `tipo` (todos|comisiones|pagos), `page`.
     *
     * GET seller-commission-panel/{seller_id}/{moneda_id}/liquidadas
     * Respuesta: paginador de Laravel (data, current_page, last_page, per_page, total, ...).
     *
     * @param \Illuminate\Http\Request $request
     * @param int $seller_id
     * @param int $moneda_id
     * @return \Illuminate\Http\JsonResponse
     */
    function panelLiquidadas(Request $request, $seller_id, $moneda_id) {

        $paginador = PanelComisionesHelper::liquidadas(
            $this->userId(),
            $seller_id,
            $moneda_id,
            $request->query('desde'),
            $request->query('hasta'),
            $request->query('tipo'),
            $request->query('page')
        );

        return response()->json($paginador, 200);
    }

    /**
     * Panel de comisiones del vendedor: tabla de pendientes (comisiones `inactive`), paginada de a
     * 15. Query params opcionales: `desde`, `hasta` (sobre la fecha de la venta), `page`.
     *
     * GET seller-commission-panel/{seller_id}/{moneda_id}/pendientes
     * Respuesta: paginador de Laravel, sin `saldo_calculado`.
     *
     * @param \Illuminate\Http\Request $request
     * @param int $seller_id
     * @param int $moneda_id
     * @return \Illuminate\Http\JsonResponse
     */
    function panelPendientes(Request $request, $seller_id, $moneda_id) {

        $paginador = PanelComisionesHelper::pendientes(
            $this->userId(),
            $seller_id,
            $moneda_id,
            $request->query('desde'),
            $request->query('hasta'),
            $request->query('page')
        );

        return response()->json($paginador, 200);
    }

    /**
     * Grupo 268 · Prompt 03: contempla moneda (default 1) y liquidada_at, y recalcula saldos
     * despues de crear (antes seteaba el saldo a mano con un criterio ya eliminado).
     *
     * Mision saldo-inicial-vendedor (10/10/2026): el saldo inicial se carga UNA sola vez, antes
     * del primer movimiento del vendedor en esa moneda, y tiene que traer un importe. Antes la API
     * no frenaba nada: se podia cargar dos veces (el SPA dejaba el boton a la vista despues de
     * guardar) y con el debe y el haber vacios quedaba una fila sin importe que encima escondia el
     * boton para siempre. Las dos cosas responden 422 `{error, message}` sin crear nada; el SPA ya
     * muestra `message` en el toast.
     *
     * Respuesta de exito sin cambios: 201 `{model: Seller}` con `seller_commissions_count`.
     */
    function saldoInicial(Request $request) {

        $moneda_id = $request->moneda_id;
        if (is_null($moneda_id) || $moneda_id == 0) {
            $moneda_id = 1;
        }

        // Un importe es un numero mayor a cero, redondeado a centavos (mismo criterio que
        // CurrentAcountController::saldoInicial). Lo demas (vacio, cero, negativo, texto) no es un
        // saldo y se guarda como null: un 0 en el haber haria que la tabla del modal mostrara
        // "− $0" o tomara la fila por un pago.
        $debe = $this->importe_del_saldo_inicial($request->debe);
        $haber = $this->importe_del_saldo_inicial($request->haber);

        if (is_null($debe) && is_null($haber)) {
            return response()->json([
                'error'   => true,
                'message' => 'Ingresá el saldo inicial en el debe o en el haber.',
            ], 422);
        }

        $ya_tiene_movimientos = false;

        DB::transaction(function () use ($request, $moneda_id, $debe, $haber, &$ya_tiene_movimientos) {

            // El candado serializa dos POST simultaneos (doble Enter con un SPA viejo, dos
            // pestañas): sin el, los dos preguntan si hay movimientos, ninguno ve al otro todavia
            // y se cargan dos saldos iniciales. Se bloquea la fila del dueño en `users`, que es el
            // mismo candado que `num()` toma enseguida: no suma ninguna espera nueva ni cambia el
            // orden de los candados (mismo criterio que BuyerController@store). La consulta de
            // abajo es la primera lectura comun de la transaccion, asi que ya ve lo que commiteo
            // el POST que se estaba esperando.
            DB::table('users')->where('id', $this->userId())->lockForUpdate()->first(['id']);

            // Cualquier movimiento en esa moneda cuenta, liquidado o pendiente: una comision
            // pendiente ya es parte de la historia del vendedor. `moneda_id` nulo es pesos, el
            // mismo criterio que ComisionesHelper::recalcular_saldos.
            $ya_tiene_movimientos = SellerCommission::where('seller_id', $request->seller_id)
                                        ->where(function ($q) use ($moneda_id) {
                                            if ($moneda_id == 1) {
                                                $q->where('moneda_id', 1)->orWhereNull('moneda_id');
                                            } else {
                                                $q->where('moneda_id', $moneda_id);
                                            }
                                        })
                                        ->exists();

            if ($ya_tiene_movimientos) {
                return;
            }

            // La descripcion dice lo que es. Antes guardaba la de los pagos ("Pago a vendedor")
            // aun cuando el saldo estaba en el debe; SellerCommissionHelper::getDescription() la
            // siguen usando los pagos y por eso no se toca.
            SellerCommission::create([
                'num'           => $this->num('seller_commissions'),
                'status'        => 'active',
                'seller_id'     => $request->seller_id,
                'moneda_id'     => $moneda_id,
                'liquidada_at'  => now(),
                'description'   => 'Saldo inicial',
                'debe'          => $debe,
                'haber'         => $haber,
                'user_id'       => $this->userId(),
            ]);

            ComisionesHelper::recalcular_saldos($request->seller_id, $moneda_id);
        });

        if ($ya_tiene_movimientos) {
            // `model` va también en el 422: el SPA lo usa para esconder el botón "Saldo inicial"
            // cuando lo que tenía abierto era un vendedor viejo (por ejemplo, otra pestaña ya le
            // cargó un movimiento). Un SPA que no lo lee solo muestra `message`.
            return response()->json([
                'error'   => true,
                'message' => 'Este vendedor ya tiene movimientos: el saldo inicial se carga una sola vez, antes del primer movimiento.',
                'model'   => $this->fullModel('Seller', $request->seller_id),
            ], 422);
        }

        return response()->json(['model' => $this->fullModel('Seller', $request->seller_id)], 201);
    }

    /**
     * Importe de un lado (debe o haber) del saldo inicial: el numero redondeado a centavos si es
     * mayor a cero, o null.
     *
     * 🔴 `is_numeric` y no un `(float)` a secas: el input admite coma decimal y punto de miles, y
     * `(float) '1.234,56'` da 1.234 y `(float) '50,5'` da 50 — se guardaria otro importe sin
     * avisar (antes MySQL los rechazaba con un 500). Y el redondeo antes de comparar, porque la
     * columna es decimal(14,2): 0,001 pasaria la guarda y quedaria guardado como 0,00.
     *
     * @param mixed $valor
     * @return float|null
     */
    protected function importe_del_saldo_inicial($valor) {
        if (!is_numeric($valor)) {
            return null;
        }
        $importe = round((float) $valor, 2);
        return $importe > 0 ? $importe : null;
    }

    /**
     * Grupo 268 · Prompt 03: el pago pasa a soportar varios metodos de pago en un solo
     * movimiento, cada uno con su caja, generando egresos reales de caja (antes era un `haber`
     * suelto que no tocaba ninguna caja). Logica completa en PagoVendedorHelper.
     */
    function pago(Request $request) {

        $payment_methods = $request->current_acount_payment_methods;

        if (is_null($payment_methods) || count($payment_methods) == 0) {
            return response()->json([
                'error'   => true,
                'message' => 'Elegí al menos un método de pago.',
            ], 422);
        }

        $seller_commission = PagoVendedorHelper::pagar($request, $request->seller_id);

        if (is_null($seller_commission)) {
            return response()->json([
                'error'   => true,
                'message' => 'El monto del pago tiene que ser mayor a cero.',
            ], 422);
        }

        return response()->json(['model' => $seller_commission], 201);
    }

    /**
     * Grupo 268 · Prompt 03: si la fila es un pago (`haber` no nulo) con metodos de pago que
     * tienen caja asociada, bloquea el borrado con 422 en vez de intentar revertir el movimiento
     * de caja (adivinar que movimiento corresponde a que metodo es peor que un borrado bloqueado
     * y explicado). Despues de borrar, recalcula saldos (bug B, ya no hay checkSaldos()).
     */
    function destroy($id) {

        $model = SellerCommission::find($id);

        if (!is_null($model->haber)) {

            $model->load('payment_methods');

            $tiene_caja = false;
            foreach ($model->payment_methods as $payment_method) {
                if (!is_null($payment_method->pivot->caja_id)) {
                    $tiene_caja = true;
                    break;
                }
            }

            if ($tiene_caja) {
                return response()->json([
                    'error'   => true,
                    'message' => 'Este pago generó movimientos de caja. Eliminalo desde la caja correspondiente.',
                ], 422);
            }
        }

        $model->delete();

        $moneda_id = !is_null($model->moneda_id) ? $model->moneda_id : 1;
        ComisionesHelper::recalcular_saldos($model->seller_id, $moneda_id);

        return response(null, 200);
    }
}
