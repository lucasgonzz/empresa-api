<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Http\Controllers\Helpers\caja\MovimientoEntreCajaHelper;
use App\Models\MovimientoEntreCaja;
use Illuminate\Http\Request;

class MovimientoEntreCajaController extends Controller
{

    public function index($from_date = null, $until_date = null) {
        $models = MovimientoEntreCaja::where('user_id', $this->userId())
                        ->orderBy('created_at', 'DESC')
                        ->withAll();
        if (!is_null($from_date)) {
            if (!is_null($until_date)) {
                $models = $models->whereDate('created_at', '>=', $from_date)
                                ->whereDate('created_at', '<=', $until_date);
            } else {
                $models = $models->whereDate('created_at', $from_date);
            }
        }

        $models = $models->get();
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        $model = MovimientoEntreCaja::create([
            'num'                   => $this->num('movimiento_entre_cajas'),
            'from_caja_id'          => $request->from_caja_id,
            'to_caja_id'            => $request->to_caja_id,
            'amount'                => $request->amount,
            'employee_id'           => $this->userId(false),
            'user_id'               => $this->userId(),
        ]);

        $helper = new MovimientoEntreCajaHelper();
        $helper->mover($model);

        // $this->sendAddModelNotification('MovimientoEntreCaja', $model->id);
        return response()->json(['model' => $this->fullModel('MovimientoEntreCaja', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('MovimientoEntreCaja', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = MovimientoEntreCaja::find($id);
        $model->name                = $request->name;
        $model->save();
        $this->sendAddModelNotification('MovimientoEntreCaja', $model->id);
        return response()->json(['model' => $this->fullModel('MovimientoEntreCaja', $model->id)], 200);
    }

    public function destroy($id) {
        $model = MovimientoEntreCaja::find($id);
        ImageController::deleteModelImages($model);
        $model->delete();
        $this->sendDeleteModelNotification('MovimientoEntreCaja', $model->id);
        return response(null);
    }
}
