<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\CommonLaravel\ImageController;
use App\Http\Controllers\Helpers\MessageHelper;
use App\Http\Controllers\Helpers\OrderHelper;
use App\Http\Controllers\Pdf\OrderPdf;
use App\Models\Order;
use Illuminate\Http\Request;

class OrderController extends Controller
{

    public function index($from_date, $until_date = null) {
        $models = Order::where('user_id', $this->userId())
                        ->orderBy('created_at', 'DESC')
                        ->withAll();
        if (!is_null($until_date)) {
            $models = $models->whereDate('created_at', '>=', $from_date)
                            ->whereDate('created_at', '<=', $until_date);
        } else {
            $models = $models->whereDate('created_at', $from_date);
        }

        $models = $models->get();
        $models = OrderHelper::setArticlesVariant($models);
        return response()->json(['models' => $models], 200);
    }

    function indexUnconfirmed() {
        $models = Order::where('user_id', $this->userId())
                        ->where('order_status_id', 1)
                        ->withAll()
                        ->get();
        return response()->json(['models' => $models], 200);
    }

    function updateStatus(Request $request, $id) {
        $model = Order::find($id);
        OrderHelper::discountArticleStock($model);
        $model->order_status_id = $request->order_status_id;
        $model->save();
        $model = Order::find($id);
        OrderHelper::sendMail($model);
        OrderHelper::saveSale($model, $this);
        $this->sendAddModelNotification('Order', $model->id);
        return response()->json(['model' => $this->fullModel('Order', $model->id)], 200);
    }

    function cancel(Request $request, $id) {
        $model = Order::find($id);
        $model->order_status_id = $this->getModelBy('order_statuses', 'name', 'Cancelado', false, 'id');
        $model->save();
        OrderHelper::restartArticleStock($model);
        MessageHelper::sendOrderCanceledMessage($request->description, $model);
        return response()->json(['model' => $this->fullModel('Order', $model->id)], 200);
    }

    public function show($id) {
        return response()->json(['model' => $this->fullModel('Order', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = Order::find($id);
        GeneralHelper::attachModels($model, 'articles', $request->articles, ['price', 'amount']);
        $this->sendAddModelNotification('Order', $model->id);
        return response()->json(['model' => $this->fullModel('Order', $model->id)], 200);
    }

    public function destroy($id) {
        $model = Order::find($id);
        $model->delete();
        ImageController::deleteModelImages($model);
        $this->sendDeleteModelNotification('Order', $model->id);
        return response(null);
    }

    function pdf($id) {
        $model = Order::find($id);
        new OrderPdf($model);
    }
}
