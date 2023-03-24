<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Http\Controllers\Helpers\CurrentAcountHelper;
use App\Http\Controllers\Helpers\OrderProductionHelper;
use App\Http\Controllers\Helpers\SaleHelper;
use App\Http\Controllers\Pdf\OrderProductionArticlesPdf;
use App\Http\Controllers\Pdf\OrderProductionPdf;
use App\Models\OrderProduction;
use Carbon\Carbon;
use Illuminate\Http\Request;

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
        OrderProductionHelper::checkFinieshed($model);
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

    public function destroy($id) {
        $model = OrderProduction::find($id);
        if (OrderProductionHelper::deleteCurrentAcount($model)) {
            CurrentAcountHelper::checkSaldos('client', $model->client_id);
            SaleHelper::deleteSaleFrom('order_production', $model->id, $this);
            $this->sendAddModelNotification('client', $model->client_id, false);
        }
        $model->delete();
        ImageController::deleteModelImages($model);
        $this->sendDeleteModelNotification('order_production', $model->id);
        return response(null);
    }

    function pdf($id, $with_prices) {
        $order_production = OrderProduction::find($id);
        $pdf = new OrderProductionPdf($order_production, $with_prices);
    }

    function articlesPdf($id) {
        $order_production = OrderProduction::find($id);
        $pdf = new OrderProductionArticlesPdf($order_production);
    }
}
