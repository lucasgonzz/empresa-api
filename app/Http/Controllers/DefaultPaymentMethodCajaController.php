<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\DefaultPaymentMethodCaja;
use Illuminate\Http\Request;

class DefaultPaymentMethodCajaController extends Controller
{

    public function index() {
        $models = DefaultPaymentMethodCaja::where('user_id', $this->userId())
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        $model = DefaultPaymentMethodCaja::create([
            'caja_id'                           => $request->caja_id,
            'current_acount_payment_method_id'  => $request->current_acount_payment_method_id,
            'address_id'                        => $request->address_id,
            'employee_id'                        => $request->employee_id,
            'user_id'                           => $this->userId(),
        ]);
        $this->sendAddModelNotification('DefaultPaymentMethodCaja', $model->id);
        return response()->json(['model' => $this->fullModel('DefaultPaymentMethodCaja', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('DefaultPaymentMethodCaja', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = DefaultPaymentMethodCaja::find($id);
        $model->caja_id                           = $request->caja_id;
        $model->current_acount_payment_method_id  = $request->current_acount_payment_method_id;
        $model->address_id                        = $request->address_id;
        $model->employee_id                        = $request->employee_id;
        $model->save();
        $this->sendAddModelNotification('DefaultPaymentMethodCaja', $model->id);
        return response()->json(['model' => $this->fullModel('DefaultPaymentMethodCaja', $model->id)], 200);
    }

    public function destroy($id) {
        $model = DefaultPaymentMethodCaja::find($id);
        ImageController::deleteModelImages($model);
        $model->delete();
        $this->sendDeleteModelNotification('DefaultPaymentMethodCaja', $model->id);
        return response(null);
    }
}
