<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Http\Controllers\Helpers\caja\MovimientoCajaHelper;
use App\Models\MovimientoCaja;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MovimientoCajaController extends Controller
{

    public function index($apertura_caja_id) {
        $models = MovimientoCaja::where('apertura_caja_id', $apertura_caja_id)
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {

        $data = [
            'concepto_movimiento_caja_id'   => $request->concepto_movimiento_caja_id,
            'ingreso'                       => $request->ingreso,
            'egreso'                        => $request->egreso,
            'notas'                         => $request->notas,
            'apertura_caja_id'              => $request->apertura_caja_id,
            'caja_id'                       => $request->caja_id,
        ];

        $helper = new MovimientoCajaHelper();
        $movimiento_caja = $helper->crear_movimiento($data);

        return response()->json(['model' => $movimiento_caja], 201);
        // return response()->json(['model' => $this->fullModel('MovimientoCaja', $movimiento_caja->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('MovimientoCaja', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = MovimientoCaja::find($id);
        $model->concepto_movimiento_caja_id   = $request->concepto_movimiento_caja_id;
        $model->ingreso                       = $request->ingreso;
        $model->egreso                        = $request->egreso;
        $model->notas                         = $request->notas;
        $model->save();

        MovimientoCajaHelper::recalcular_saldos($model);

        $this->sendAddModelNotification('MovimientoCaja', $model->id);
        return response()->json(['model' => $this->fullModel('MovimientoCaja', $model->id)], 200);
    }

    public function destroy($id) {
        $model = MovimientoCaja::find($id);

        $apertura_caja_id = $model->apertura_caja_id;
        $caja_id = $model->caja_id;

        ImageController::deleteModelImages($model);
        $model->delete();

        MovimientoCajaHelper::recalcular_saldos(null, $apertura_caja_id);

        Log::info('Se elimnio momiento de caja');
        
        $this->sendDeleteModelNotification('MovimientoCaja', $model->id);
        return response(null);
    }
}
