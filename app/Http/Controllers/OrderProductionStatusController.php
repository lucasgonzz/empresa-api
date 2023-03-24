<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\OrderProductionStatus;
use Illuminate\Http\Request;

class OrderProductionStatusController extends Controller
{

    public function index() {
        $models = OrderProductionStatus::where('user_id', $this->userId())
                            ->orderBy('position', 'ASC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        $model = OrderProductionStatus::create([
            // 'num'                   => $this->num('order_production_statuses'),
            'name'                  => $request->name,
            'position'              => $request->position,
            'user_id'               => $this->userId(),
        ]);
        $this->sendAddModelNotification('order_production_status', $model->id);
        return response()->json(['model' => $this->fullModel('OrderProductionStatus', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('OrderProductionStatus', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = OrderProductionStatus::find($id);
        $model->name                = $request->name;
        $model->position                = $request->position;
        $model->save();
        $this->sendAddModelNotification('order_production_status', $model->id);
        return response()->json(['model' => $this->fullModel('OrderProductionStatus', $model->id)], 200);
    }

    public function destroy($id) {
        $model = OrderProductionStatus::find($id);
        $model->delete();
        ImageController::deleteModelImages($model);
        $this->sendDeleteModelNotification('order_production_status', $model->id);
        return response(null);
    }
}
