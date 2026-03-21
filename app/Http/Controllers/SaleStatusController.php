<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\SaleStatus;
use Illuminate\Http\Request;

class SaleStatusController extends Controller
{

    public function index() {
        $models = SaleStatus::where('user_id', $this->userId())
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }


    public function store(Request $request) {
        $model = SaleStatus::create([
            'name'                  => $request->name,
            'position'              => $request->position,
            'description'           => $request->description,
            'user_id'               => $this->userId(),
        ]);
        $this->sendAddModelNotification('SaleStatus', $model->id);
        return response()->json(['model' => $this->fullModel('SaleStatus', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('SaleStatus', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = SaleStatus::find($id);
        $model->name                = $request->name;
        $model->position            = $request->position;
        $model->description         = $request->description;
        $model->save();
        $this->sendAddModelNotification('SaleStatus', $model->id);
        return response()->json(['model' => $this->fullModel('SaleStatus', $model->id)], 200);
    }

    public function destroy($id) {
        $model = SaleStatus::find($id);
        ImageController::deleteModelImages($model);
        $model->delete();
        $this->sendDeleteModelNotification('SaleStatus', $model->id);
        return response(null);
    }
}
