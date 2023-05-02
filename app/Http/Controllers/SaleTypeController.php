<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\SaleType;
use Illuminate\Http\Request;

class SaleTypeController extends Controller
{

    public function index() {
        $models = SaleType::where('user_id', $this->userId())
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        $model = SaleType::create([
            'num'                   => $this->num('SaleType'),
            'name'                  => $request->name,
            'user_id'               => $this->userId(),
        ]);
        $this->sendAddModelNotification('SaleType', $model->id);
        return response()->json(['model' => $this->fullModel('SaleType', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('SaleType', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = SaleType::find($id);
        $model->name                = $request->name;
        $model->save();
        $this->sendAddModelNotification('SaleType', $model->id);
        return response()->json(['model' => $this->fullModel('SaleType', $model->id)], 200);
    }

    public function destroy($id) {
        $model = SaleType::find($id);
        ImageController::deleteModelImages($model);
        $model->delete();
        $this->sendDeleteModelNotification('SaleType', $model->id);
        return response(null);
    }
}
