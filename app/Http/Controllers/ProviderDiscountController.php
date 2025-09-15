<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\ProviderDiscount;
use Illuminate\Http\Request;

class ProviderDiscountController extends Controller
{

    public function store(Request $request) {
        $model = ProviderDiscount::create([
            'percentage'                  => $request->percentage,
            'provider_id'                   => $request->model_id,
        ]);
        $this->sendAddModelNotification('ProviderDiscount', $model->id);
        return response()->json(['model' => $this->fullModel('ProviderDiscount', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('ProviderDiscount', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = ProviderDiscount::find($id);
        $model->percentage                = $request->percentage;
        $model->save();
        $this->sendAddModelNotification('ProviderDiscount', $model->id);
        return response()->json(['model' => $this->fullModel('ProviderDiscount', $model->id)], 200);
    }

    public function destroy($id) {
        $model = ProviderDiscount::find($id);
        ImageController::deleteModelImages($model);
        $model->delete();
        $this->sendDeleteModelNotification('ProviderDiscount', $model->id);
        return response(null);
    }
}
