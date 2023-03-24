<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\OrderStatus;
use Illuminate\Http\Request;

class OrderStatusController extends Controller
{

    public function index() {
        $models = OrderStatus::orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        $model = OrderStatus::create([
            'num'                   => $this->num('order_statuses'),
            'name'                  => $request->name,
            'user_id'               => $this->userId(),
        ]);
        $this->sendAddModelNotification('OrderStatus', $model->id);
        return response()->json(['model' => $this->fullModel('OrderStatus', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('OrderStatus', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = OrderStatus::find($id);
        $model->name                = $request->name;
        $model->save();
        $this->sendAddModelNotification('OrderStatus', $model->id);
        return response()->json(['model' => $this->fullModel('OrderStatus', $model->id)], 200);
    }

    public function destroy($id) {
        $model = OrderStatus::find($id);
        $model->delete();
        ImageController::deleteModelImages($model);
        $this->sendDeleteModelNotification('OrderStatus', $model->id);
        return response(null);
    }
}
