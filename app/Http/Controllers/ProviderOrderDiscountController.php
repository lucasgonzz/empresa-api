<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\ProviderOrderDiscount;
use Illuminate\Http\Request;

class ProviderOrderDiscountController extends Controller
{

    public function index() {
        $models = ProviderOrderDiscount::where('user_id', $this->userId())
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        $model = ProviderOrderDiscount::create([
            'description'               => $request->description,
            'percentage'                => $request->percentage,
            'monto'                     => $request->monto,
            'provider_order_id'         => $request->model_id,
            'temporal_id'               => $this->getTemporalId($request),
        ]);

        $this->updateRelationsCreated('provider_order_discount', $model->id, $request->childrens);

        $this->sendAddModelNotification('ProviderOrderDiscount', $model->id);
        return response()->json(['model' => $this->fullModel('ProviderOrderDiscount', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('ProviderOrderDiscount', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = ProviderOrderDiscount::find($id);
        $model->description               = $request->description;
        $model->percentage                = $request->percentage;
        $model->monto                     = $request->monto;
        $model->save();
        $this->sendAddModelNotification('ProviderOrderDiscount', $model->id);
        return response()->json(['model' => $this->fullModel('ProviderOrderDiscount', $model->id)], 200);
    }

    public function destroy($id) {
        $model = ProviderOrderDiscount::find($id);
        ImageController::deleteModelImages($model);
        $model->delete();
        $this->sendDeleteModelNotification('ProviderOrderDiscount', $model->id);
        return response(null);
    }
}
