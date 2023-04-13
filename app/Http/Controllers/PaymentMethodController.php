<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\PaymentMethod;
use Illuminate\Http\Request;

class PaymentMethodController extends Controller
{

    public function index() {
        $models = PaymentMethod::where('user_id', $this->userId())
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        $model = PaymentMethod::create([
            // 'num'                       => $this->num('payment_methods'),
            'name'                      => $request->name,
            'description'               => $request->description,
            'discount'                  => $request->discount,
            'payment_method_type_id'    => $request->payment_method_type_id,
            'public_key'                => $request->public_key,
            'access_token'              => $request->access_token,
            'user_id'                   => $this->userId(),
        ]);
        $this->sendAddModelNotification('payment_method', $model->id);
        return response()->json(['model' => $this->fullModel('PaymentMethod', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('PaymentMethod', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = PaymentMethod::find($id);
        $model->name                        = $request->name;
        $model->description                 = $request->description;
        $model->discount                    = $request->discount;
        $model->payment_method_type_id      = $request->payment_method_type_id;
        $model->public_key                  = $request->public_key;
        $model->access_token                = $request->access_token;
        $model->save();
        $this->sendAddModelNotification('payment_method', $model->id);
        return response()->json(['model' => $this->fullModel('PaymentMethod', $model->id)], 200);
    }

    public function destroy($id) {
        $model = PaymentMethod::find($id);
        $model->delete();
        ImageController::deleteModelImages($model);
        $this->sendDeleteModelNotification('payment_method', $model->id);
        return response(null);
    }
}
