<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\ProductionBatchMovementType;
use Illuminate\Http\Request;

class ProductionBatchMovementTypeController extends Controller
{

    public function index() {
        $models = ProductionBatchMovementType::where('user_id', $this->userId())
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }


    public function store(Request $request) {
        $model = ProductionBatchMovementType::create([
            'name'                  => $request->name,
            'user_id'               => $this->userId(),
        ]);
        $this->sendAddModelNotification('ProductionBatchMovementType', $model->id);
        return response()->json(['model' => $this->fullModel('ProductionBatchMovementType', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('ProductionBatchMovementType', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = ProductionBatchMovementType::find($id);
        $model->name                = $request->name;
        $model->save();
        $this->sendAddModelNotification('ProductionBatchMovementType', $model->id);
        return response()->json(['model' => $this->fullModel('ProductionBatchMovementType', $model->id)], 200);
    }

    public function destroy($id) {
        $model = ProductionBatchMovementType::find($id);
        ImageController::deleteModelImages($model);
        $model->delete();
        $this->sendDeleteModelNotification('ProductionBatchMovementType', $model->id);
        return response(null);
    }
}
