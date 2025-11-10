<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\CommonLaravel\ImageController;
use App\Http\Controllers\Helpers\caja\CajaAperturaHelper;
use App\Http\Controllers\Helpers\caja\CajaCierreHelper;
use App\Models\Caja;
use Illuminate\Http\Request;

class CajaController extends Controller
{

    public function index() {
        $models = Caja::where('user_id', $this->userId())
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }


    public function store(Request $request) {
        $model = Caja::create([
            'num'                   => $this->num('cajas'),
            'name'                  => $request->name,
            'moneda_id'             => $request->moneda_id,
            'address_id'            => $request->address_id,
            'employee_id'            => $request->employee_id,
            'notas'                 => $request->notas,
            'user_id'               => $this->userId(),
        ]);

        // GeneralHelper::attachModels($model, 'current_acount_payment_methods', $request->current_acount_payment_methods);

        GeneralHelper::attachModels($model, 'users', $request->users);
        
        $this->sendAddModelNotification('Caja', $model->id);
        return response()->json(['model' => $this->fullModel('Caja', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('Caja', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = Caja::find($id);
        $model->name                = $request->name;
        $model->notas               = $request->notas;
        $model->moneda_id           = $request->moneda_id;
        $model->address_id          = $request->address_id;
        $model->employee_id          = $request->employee_id;
        $model->save();

        GeneralHelper::attachModels($model, 'current_acount_payment_methods', $request->current_acount_payment_methods);

        GeneralHelper::attachModels($model, 'users', $request->users);
        
        $this->sendAddModelNotification('Caja', $model->id);
        return response()->json(['model' => $this->fullModel('Caja', $model->id)], 200);
    }

    function abrir_caja($caja_id) {

        $helper = new CajaAperturaHelper($caja_id);
        $helper->abrir_caja();

        return response()->json(['model' => $this->fullModel('Caja', $caja_id)], 200);
    }

    function cerrar_caja($caja_id) {

        $helper = new CajaCierreHelper($caja_id);
        $helper->cerrar_caja();

        return response()->json(['model' => $this->fullModel('Caja', $caja_id)], 200);
    }

    public function destroy($id) {
        $model = Caja::find($id);
        ImageController::deleteModelImages($model);
        $model->delete();
        $this->sendDeleteModelNotification('Caja', $model->id);
        return response(null);
    }
}
