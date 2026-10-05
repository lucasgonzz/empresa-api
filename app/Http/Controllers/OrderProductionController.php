<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Http\Controllers\Helpers\CurrentAcountHelper;
use App\Http\Controllers\Helpers\OrderProductionHelper;
use App\Http\Controllers\Helpers\currentAcount\CuentaCorrienteLock;
use App\Http\Controllers\Helpers\sale\DeleteSaleHelper;
use App\Http\Controllers\Pdf\OrderProductionArticlesPdf;
use App\Http\Controllers\Pdf\OrderProductionPdf;
use App\Models\OrderProduction;
use App\Models\Sale;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderProductionController extends Controller
{

    public function index() {
        $models = OrderProduction::where('user_id', $this->userId())
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }


    public function store(Request $request) {
        $model = OrderProduction::create([
            'num'                           => $this->num('order_productions'),
            'client_id'                     => $request->client_id,
            'observations'                  => $request->observations,
            'start_at'                      => $request->start_at,
            'finish_at'                     => $request->finish_at,
            'order_production_status_id'    => $request->order_production_status_id,
            'finished'                      => $request->finished,
            'budget_id'                     => isset($request->budget_id) ? $request->budget_id : null,
            'user_id'                       => $this->userId(),
        ]);
        OrderProductionHelper::attachArticles($model, $request->articles);
        // OrderProductionHelper::checkFinieshed($model);
        $this->sendAddModelNotification('order_production', $model->id);
        // $model = OrderProductionHelper::setArticles([$this->fullModel($model->id)])[0];
        return response()->json(['model' => $this->fullModel('OrderProduction', $model->id)], 201);
    }

    public function show($id) {
        return response()->json(['model' => $this->fullModel('OrderProduction', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = OrderProduction::find($id);
        $model->client_id                       = $request->client_id;
        $model->observations                    = $request->observations;
        $model->start_at                        = $request->start_at;
        $model->finish_at                       = $request->finish_at;
        $model->order_production_status_id      = $request->order_production_status_id;
        $model->finished                        = $request->finished;
        $model->save();
        OrderProductionHelper::attachArticles($model, $request->articles);
        OrderProductionHelper::checkFinieshed($model);
        $this->sendAddModelNotification('order_production', $model->id);
        // $model = OrderProductionHelper::setArticles([$this->fullModel($model->id)])[0];
        return response()->json(['model' => $this->fullModel('OrderProduction', $model->id)], 200);
    }

    /**
     * Borra una orden de producción junto con lo que generó al terminarse: su venta y su
     * movimiento de cuenta corriente.
     *
     * A este destroy() llegan tres entradas: el botón del listado, el borrado masivo
     * (DeleteModelsHelper::process_delete, que respeta el 422 porque `order_production` está en
     * MODELOS_QUE_RESPETAN_RECHAZO) y el borrado por pantalla del asistente.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response|\Illuminate\Http\JsonResponse
     */
    public function destroy($id) {
        $model = OrderProduction::find($id);

        if (is_null($model)) {
            Log::info('destroy order_production: no existe la orden id '.$id.'. Se responde 404 sin tocar nada.');
            return response()->json(['message' => 'La orden de producción no existe o ya fue eliminada.'], 404);
        }

        /*
            La venta se busca SIEMPRE, no solo si la orden tenía cuenta corriente (misión
            orden-produccion-baja-de-venta, 5/10/2026). Antes se la buscaba adentro del
            `if (deleteCurrentAcount(...))`: una orden que quedaba con venta y sin movimiento (el
            medio estado que deja un terminar fallido, o un reintento del borrado después de un 500)
            se borraba dejando la venta viva y huérfana. `Sale` usa SoftDeletes: esto trae solo la viva.
        */
        $sale = Sale::where('order_production_id', $model->id)->first();

        /*
            🔴 La guarda va ANTES de tocar nada, y en particular antes de la cuenta corriente
            (misión orden-produccion-baja-de-venta, 5/10/2026). El destroy() viejo borraba primero
            el movimiento de la orden, fuera de toda transacción, y recién después miraba la venta:
            un rechazo (o un 500) a esa altura dejaba la cuenta del cliente sin el débito y la orden
            viva. Una venta facturada no se borra (su factura desaparecería del Libro IVA mientras
            sigue vigente en ARCA), así que la orden tampoco: el criterio es el mismo de
            SaleController::destroy(), en DeleteSaleHelper::motivo_por_el_que_no_se_puede_eliminar().

            `error_venta_facturada` es la misma clave del 422 de SaleController y OrderController.
            Es opcional: la SPA vieja muestra el `message` con el interceptor global y deja la fila.
        */
        if (!is_null($sale)) {

            $motivo = DeleteSaleHelper::motivo_por_el_que_no_se_puede_eliminar($sale);

            if (!is_null($motivo)) {

                $num_orden = !is_null($model->num) ? $model->num : $model->id;
                $num_venta = !is_null($sale->num) ? $sale->num : $sale->id;

                $mensaje = 'No se puede eliminar la orden de producción N° '.$num_orden.' porque su venta N° '.$num_venta.' no se puede borrar. '.$motivo;

                Log::info('destroy order_production id '.$id.': rechazado. '.$mensaje);

                return response()->json(['message' => $mensaje, 'error_venta_facturada' => true], 422);
            }
        }

        /*
            Todo lo que escribe va en UNA transacción: si algo revienta en el medio, no queda ni la
            cuenta corriente sin el débito con la orden viva (lo que pasaba hasta el 5/10/2026) ni la
            venta borrada con la orden viva.
        */
        $movimientos_borrados = DB::transaction(function () use ($model, $sale) {

            if (!is_null($sale)) {

                /*
                    🔴 La venta se da de baja por el flujo REAL, no con un `$sale->delete()` crudo
                    (lo que hacía SaleHelper::deleteSaleFrom(), que se borró en esta misión): así se
                    devuelve el stock que el libro de la venta dice que se descontó, se sacan sus
                    movimientos de cuenta corriente, comisiones y puntos, igual que al borrarla
                    desde Ventas. Una venta de orden nace sin descontar stock
                    (OrderProductionHelper::saveSale), y regresar_stock() lo respeta: sin movimientos
                    en el libro, no repone nada.

                    `compensar_caja` en false: la venta de una orden nace sin cobro ni métodos de
                    pago. Mismo criterio que la cancelación de un pedido y que el borrado masivo.

                    eliminar_venta() toma el candado de la venta y después el de la cuenta del
                    cliente: el orden venta → cuenta del resto del sistema.
                */
                DeleteSaleHelper::eliminar_venta($sale, $this, false);
            }

            // Gratis si eliminar_venta() ya lo tomó; si la orden no tenía venta, es la primera
            // sentencia de la transacción, que es donde tiene que estar (ver CuentaCorrienteLock).
            CuentaCorrienteLock::bloquear('client', $model->client_id);

            $movimientos = OrderProductionHelper::deleteCurrentAcount($model);

            /*
                Se recalcula la cadena de cada cuenta de la que salió un movimiento, por su
                `credit_account_id`. 🔴 No volver a `CurrentAcountHelper::checkSaldos('client',
                $client_id)`: esa firma murió el 10/9/2025 (f5ff6d0d) y tiraba un 500 después de
                haber borrado el movimiento.
            */
            $credit_account_ids = [];

            foreach ($movimientos as $movimiento) {

                if (is_null($movimiento->credit_account_id)) {

                    // La basura que deja hoy el terminar roto (saveCurrentAcount con la firma
                    // vieja de getSaldo): no está en ninguna cadena, no hay nada que recalcular.
                    Log::info('destroy order_production id '.$model->id.': se borró el movimiento de cuenta corriente '.$movimiento->id.' sin credit_account_id. No hay cadena que recalcular.');

                    continue;
                }

                $credit_account_ids[$movimiento->credit_account_id] = $movimiento->credit_account_id;
            }

            foreach ($credit_account_ids as $credit_account_id) {
                CurrentAcountHelper::check_saldos_y_pagos($credit_account_id);
            }

            $model->delete();

            return $movimientos;
        });

        ImageController::deleteModelImages($model);

        if (count($movimientos_borrados) > 0 && !is_null($model->client_id)) {
            $this->sendAddModelNotification('client', $model->client_id, false);
        }

        $this->sendDeleteModelNotification('order_production', $model->id);

        return response(null);
    }

    function pdf($id, $with_prices) {
        $order_production = OrderProduction::find($id);

        if (is_null($order_production)) {
            abort(404);
        }

        $pdf = new OrderProductionPdf($order_production, $with_prices);
    }

    function articlesPdf($id) {
        $order_production = OrderProduction::find($id);

        if (is_null($order_production)) {
            abort(404);
        }

        $pdf = new OrderProductionArticlesPdf($order_production);
    }
}
