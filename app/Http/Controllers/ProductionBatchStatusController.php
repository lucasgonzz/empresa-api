<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\ProductionBatchStatus;
use Illuminate\Http\Request;

class ProductionBatchStatusController extends Controller
{

    public function index() {
        $models = ProductionBatchStatus::where('user_id', $this->userId())
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }


    public function store(Request $request) {
        $model = ProductionBatchStatus::create([
            'name'                  => $request->name,
            'user_id'               => $this->userId(),
        ]);
        $this->sendAddModelNotification('ProductionBatchStatus', $model->id);
        return response()->json(['model' => $this->fullModel('ProductionBatchStatus', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('ProductionBatchStatus', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = ProductionBatchStatus::find($id);
        $model->name                = $request->name;
        $model->save();
        $this->sendAddModelNotification('ProductionBatchStatus', $model->id);
        return response()->json(['model' => $this->fullModel('ProductionBatchStatus', $model->id)], 200);
    }

    public function destroy($id) {
        $model = ProductionBatchStatus::find($id);
        ImageController::deleteModelImages($model);
        $model->delete();
        $this->sendDeleteModelNotification('ProductionBatchStatus', $model->id);
        return response(null);
    }
}
