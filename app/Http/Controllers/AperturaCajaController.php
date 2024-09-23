<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\AperturaCaja;
use App\Models\Caja;
use Illuminate\Http\Request;

class AperturaCajaController extends Controller
{

    public function index($caja_id) {
        $models = AperturaCaja::where('caja_id', $caja_id)
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }


    public function store(Request $request) {
        $model = AperturaCaja::create([
            'num'                   => $this->num('AperturaCaja'),
            'name'                  => $request->name,
            'user_id'               => $this->userId(),
        ]);
        $this->sendAddModelNotification('AperturaCaja', $model->id);
        return response()->json(['model' => $this->fullModel('AperturaCaja', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('AperturaCaja', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = AperturaCaja::find($id);
        $model->name                = $request->name;
        $model->save();
        $this->sendAddModelNotification('AperturaCaja', $model->id);
        return response()->json(['model' => $this->fullModel('AperturaCaja', $model->id)], 200);
    }

    function reabrir($apertura_caja_id) {
        $apertura_caja = AperturaCaja::find($apertura_caja_id);
        $apertura_caja->cerrada_at = null; 
        $apertura_caja->cierre_employee_id = null; 
        $apertura_caja->saldo_cierre = null; 
        $apertura_caja->save();

        $caja = Caja::find($apertura_caja->caja_id);
        $caja->abierta = 1;
        $caja->cerrada_at = null;
        $caja->current_apertura_caja_id = $apertura_caja->id;
        $caja->save();

        return response(null, 200);
    }

    public function destroy($id) {
        $model = AperturaCaja::find($id);
        ImageController::deleteModelImages($model);
        $model->delete();
        $this->sendDeleteModelNotification('AperturaCaja', $model->id);
        return response(null);
    }
}
