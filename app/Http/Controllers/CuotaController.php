<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\Cuota;
use Illuminate\Http\Request;

class CuotaController extends Controller
{

    public function index() {
        $models = Cuota::where('user_id', $this->userId())
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        $model = Cuota::create([
            'cantidad_cuotas'       => $request->cantidad_cuotas,
            'descuento'             => $request->descuento,
            'recargo'               => $request->recargo,
            'user_id'               => $this->userId(),
        ]);
        $this->sendAddModelNotification('Cuota', $model->id);
        return response()->json(['model' => $this->fullModel('Cuota', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('Cuota', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = Cuota::find($id);
        $model->cantidad_cuotas       = $request->cantidad_cuotas;
        $model->descuento             = $request->descuento;
        $model->recargo               = $request->recargo;
        $model->save();
        
        $this->sendAddModelNotification('Cuota', $model->id);
        return response()->json(['model' => $this->fullModel('Cuota', $model->id)], 200);
    }

    public function destroy($id) {
        $model = Cuota::find($id);
        ImageController::deleteModelImages($model);
        $model->delete();
        $this->sendDeleteModelNotification('Cuota', $model->id);
        return response(null);
    }
}
