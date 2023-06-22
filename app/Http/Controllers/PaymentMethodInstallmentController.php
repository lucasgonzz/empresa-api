<?php

namespace App\Http\Controllers;

use App\Models\PaymentMethodInstallment;
use Illuminate\Http\Request;

class PaymentMethodInstallmentController extends Controller
{

    public function store(Request $request) {
        $model = PaymentMethodInstallment::create([
            'payment_method_id'     => $request->model_id,
            'temporal_id'           => $this->getTemporalId($request),
            'name'                  => $request->name,
            'installments'          => $request->installments,
        ]);
        return response()->json(['model' => $this->fullModel('PaymentMethodInstallment', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('PaymentMethodInstallment', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = PaymentMethodInstallment::find($id);
        $model->name                        = $request->name;
        $model->installments                = $request->installments;
        $model->save();
        return response()->json(['model' => $this->fullModel('PaymentMethodInstallment', $model->id)], 200);
    }

    public function destroy($id) {
        $model = PaymentMethodInstallment::find($id);
        $model->delete();
        ImageController::deleteModelImages($model);
        $this->sendDeleteModelNotification('PaymentMethodInstallment', $model->id);
        return response(null);
    }
}
