<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\PaymentMethodType;
use Illuminate\Http\Request;

class PaymentMethodTypeController extends Controller
{

    public function index() {
        $models = PaymentMethodType::orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    // public function store(Request $request) {
    //     $model = PaymentMethodType::create([
    //         'num'                   => $this->num('PaymentMethodType'),
    //         'name'                  => $request->name,
    //         'user_id'               => $this->userId(),
    //     ]);
    //     $this->sendAddModelNotification('PaymentMethodType', $model->id);
    //     return response()->json(['model' => $this->fullModel('PaymentMethodType', $model->id)], 201);
    // }  

    // public function show($id) {
    //     return response()->json(['model' => $this->fullModel('PaymentMethodType', $id)], 200);
    // }

    // public function update(Request $request, $id) {
    //     $model = PaymentMethodType::find($id);
    //     $model->name                = $request->name;
    //     $model->save();
    //     $this->sendAddModelNotification('PaymentMethodType', $model->id);
    //     return response()->json(['model' => $this->fullModel('PaymentMethodType', $model->id)], 200);
    // }

    // public function destroy($id) {
    //     $model = PaymentMethodType::find($id);
    //     ImageController::deleteModelImages($model);
    //     $model->delete();
    //     $this->sendDeleteModelNotification('PaymentMethodType', $model->id);
    //     return response(null);
    // }
}
