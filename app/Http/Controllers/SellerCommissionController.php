<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\Helpers\Numbers;
use App\Http\Controllers\Helpers\SellerCommissionHelper;
use App\Http\Controllers\Helpers\comisiones\ComisionesHelper;
use App\Http\Controllers\Helpers\comisiones\PagoVendedorHelper;
use App\Models\SellerCommission;
use Illuminate\Http\Request;

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
     * Grupo 268 · Prompt 03: contempla moneda (default 1) y liquidada_at, y recalcula saldos
     * despues de crear (antes seteaba el saldo a mano con un criterio ya eliminado).
     */
    function saldoInicial(Request $request) {

        $moneda_id = $request->moneda_id;
        if (is_null($moneda_id) || $moneda_id == 0) {
            $moneda_id = 1;
        }

        SellerCommission::create([
            'num'           => $this->num('seller_commissions'),
            'status'        => 'active',
            'seller_id'     => $request->seller_id,
            'moneda_id'     => $moneda_id,
            'liquidada_at'  => now(),
            'description'   => SellerCommissionHelper::getDescription(),
            'debe'          => !is_null($request->debe) ? $request->debe : null,
            'haber'         => !is_null($request->haber) ? $request->haber : null,
            'user_id'       => $this->userId(),
        ]);

        ComisionesHelper::recalcular_saldos($request->seller_id, $moneda_id);

        return response()->json(['model' => $this->fullModel('Seller', $request->seller_id)], 201);
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
