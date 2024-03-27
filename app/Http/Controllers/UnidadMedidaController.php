<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\UnidadMedida;
use Illuminate\Http\Request;

class UnidadMedidaController extends Controller
{

    public function index() {
        $models = UnidadMedida::orderBy('created_at', 'DESC')
                                ->withAll()
                                ->get();
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        $model = UnidadMedida::create([
            'name'                  => $request->name,
        ]);
        // $this->sendAddModelNotification('UnidadMedida', $model->id);
        return response()->json(['model' => $this->fullModel('UnidadMedida', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('UnidadMedida', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = UnidadMedida::find($id);
        $model->name                = $request->name;
        $model->save();
        // $this->sendAddModelNotification('UnidadMedida', $model->id);
        return response()->json(['model' => $this->fullModel('UnidadMedida', $model->id)], 200);
    }

    public function destroy($id) {
        $model = UnidadMedida::find($id);
        ImageController::deleteModelImages($model);
        $model->delete();
        $this->sendDeleteModelNotification('UnidadMedida', $model->id);
        return response(null);
    }
}
