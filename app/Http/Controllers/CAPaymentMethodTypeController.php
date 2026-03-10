<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\CAPaymentMethodType;
use Illuminate\Http\Request;

class CAPaymentMethodTypeController extends Controller
{

    public function index() {
        $models = CAPaymentMethodType::orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }


    // public function store(Request $request) {
    //     $model = CAPaymentMethodType::create([
    //         'num'                   => $this->num('CAPaymentMethodType'),
    //         'name'                  => $request->name,
    //         'user_id'               => $this->userId(),
    //     ]);
    //     $this->sendAddModelNotification('CAPaymentMethodType', $model->id);
    //     return response()->json(['model' => $this->fullModel('CAPaymentMethodType', $model->id)], 201);
    // }  

    // public function show($id) {
    //     return response()->json(['model' => $this->fullModel('CAPaymentMethodType', $id)], 200);
    // }

    // public function update(Request $request, $id) {
    //     $model = CAPaymentMethodType::find($id);
    //     $model->name                = $request->name;
    //     $model->save();
    //     $this->sendAddModelNotification('CAPaymentMethodType', $model->id);
    //     return response()->json(['model' => $this->fullModel('CAPaymentMethodType', $model->id)], 200);
    // }

    // public function destroy($id) {
    //     $model = CAPaymentMethodType::find($id);
    //     ImageController::deleteModelImages($model);
    //     $model->delete();
    //     $this->sendDeleteModelNotification('CAPaymentMethodType', $model->id);
    //     return response(null);
    // }
}
