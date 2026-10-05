<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Http\Controllers\Helpers\OrderProductionHelper;
use App\Http\Controllers\Pdf\OrderProductionArticlesPdf;
use App\Http\Controllers\Pdf\OrderProductionPdf;
use App\Models\OrderProduction;
use Carbon\Carbon;
use Illuminate\Http\Request;
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
     * Borra una orden de producción junto con lo que generó al terminarse: sus ventas y sus
     * movimientos de cuenta corriente.
     *
     * A este destroy() llegan tres entradas: el botón del listado, el borrado masivo
     * (DeleteModelsHelper::process_delete, que respeta el 4xx porque `order_production` está en
     * MODELOS_QUE_RESPETAN_RECHAZO) y el borrado por pantalla del asistente.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response|\Illuminate\Http\JsonResponse
     */
    public function destroy($id) {

        /*
            🔴 Tenencia (misión orden-produccion-baja-de-venta, 5/10/2026): solo una orden del dueño.
            Hasta acá era un `find($id)` a secas, y en una base compartida
            (`u767360347_empresa`, 51 comercios adentro) un DELETE con el id de una orden ajena la
            borraba; con esta misión además daría de baja su venta, su stock, su cuenta corriente y
            sus puntos. Una orden de otro dueño responde el mismo 404 que una inexistente: no se
            confirma que el id existe. index() ya filtra por `user_id`.
        */
        $model = OrderProduction::where('id', $id)
                                ->where('user_id', $this->userId())
                                ->first();

        if (is_null($model)) {
            Log::info('destroy order_production: no existe la orden id '.$id.' para el dueño '.$this->userId().'. Se responde 404 sin tocar nada.');
            return response()->json(['message' => 'La orden de producción no existe o ya fue eliminada.'], 404);
        }

        /*
            La baja en sí (la guarda, los candados, las ventas, la cuenta corriente y la orden) vive
            en OrderProductionHelper::eliminar_orden(), en UNA transacción. 🔴 No la vuelvas a
            inlinear acá ni le agregues una guarda previa afuera: la guarda va adentro, con las
            ventas ya bloqueadas, justamente para que nada se cuele entre la pregunta y la baja.
        */
        $resultado = OrderProductionHelper::eliminar_orden($model, $this);

        if (!is_null($resultado['rechazo'])) {

            $cuerpo = ['message' => $resultado['rechazo']];

            /*
                `error_venta_facturada` solo cuando el rechazo es por un comprobante: es la misma
                clave del 422 de SaleController y OrderController. Es opcional: la SPA vieja muestra
                el `message` con el interceptor global y deja la fila en el listado.
            */
            if ($resultado['error_venta_facturada']) {
                $cuerpo['error_venta_facturada'] = true;
            }

            return response()->json($cuerpo, 422);
        }

        ImageController::deleteModelImages($model);

        if (count($resultado['movimientos']) > 0 && !is_null($model->client_id)) {
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
