<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\OrderProductionStatusGroup;
use Illuminate\Http\Request;

class OrderProductionStatusGroupController extends Controller
{

    public function index() {
        $models = OrderProductionStatusGroup::where('user_id', $this->userId())
                            ->orderBy('position', 'ASC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        $model = OrderProductionStatusGroup::create([
            'name'                  => $request->name,
            'position'              => $request->position,
            'user_id'               => $this->userId(),
        ]);
        $this->sendAddModelNotification('order_production_status_group', $model->id);
        return response()->json(['model' => $this->fullModel('OrderProductionStatusGroup', $model->id)], 201);
    }

    public function show($id) {
        return response()->json(['model' => $this->fullModel('OrderProductionStatusGroup', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = OrderProductionStatusGroup::find($id);
        $model->name                = $request->name;
        $model->position            = $request->position;
        $model->save();
        $this->sendAddModelNotification('order_production_status_group', $model->id);
        return response()->json(['model' => $this->fullModel('OrderProductionStatusGroup', $model->id)], 200);
    }

    public function destroy($id) {
        $model = OrderProductionStatusGroup::find($id);
        $model->delete();
        ImageController::deleteModelImages($model);
        $this->sendDeleteModelNotification('order_production_status_group', $model->id);
        return response(null);
    }
}
