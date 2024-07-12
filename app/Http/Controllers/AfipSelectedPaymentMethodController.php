<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\AfipSelectedPaymentMethod;
use Illuminate\Http\Request;

class AfipSelectedPaymentMethodController extends Controller
{

    public function index() {
        $models = AfipSelectedPaymentMethod::where('user_id', $this->userId())
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        $model = AfipSelectedPaymentMethod::create([
            'current_acount_payment_method_id'                  => $request->current_acount_payment_method_id,
            'user_id'               => $this->userId(),
        ]);
        $this->sendAddModelNotification('AfipSelectedPaymentMethod', $model->id);
        return response()->json(['model' => $this->fullModel('AfipSelectedPaymentMethod', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('AfipSelectedPaymentMethod', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = AfipSelectedPaymentMethod::find($id);
        $model->current_acount_payment_method_id                = $request->current_acount_payment_method_id;
        $model->save();
        $this->sendAddModelNotification('AfipSelectedPaymentMethod', $model->id);
        return response()->json(['model' => $this->fullModel('AfipSelectedPaymentMethod', $model->id)], 200);
    }

    public function destroy($id) {
        $model = AfipSelectedPaymentMethod::find($id);
        ImageController::deleteModelImages($model);
        $model->delete();
        $this->sendDeleteModelNotification('AfipSelectedPaymentMethod', $model->id);
        return response(null);
    }
}
