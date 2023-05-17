<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\ProviderOrderExtraCost;
use Illuminate\Http\Request;

class ProviderOrderExtraCostController extends Controller
{

    public function store(Request $request) {
        $model = ProviderOrderExtraCost::create([
            'description'               => $request->description,
            'value'                     => $request->value,
            'provider_order_id'         => $request->model_id,
            'temporal_id'               => $this->getTemporalId($request),
        ]);
        if (!is_null($request->model_id)) {
            $this->sendAddModelNotification('provider_order', $model->provider_order_id, false);
        }
        return response()->json(['model' => $this->fullModel('ProviderOrderExtraCost', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('ProviderOrderExtraCost', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = ProviderOrderExtraCost::find($id);
        $model->description          = $request->description;
        $model->value                = $request->value;
        $model->save();
        $this->sendAddModelNotification('provider_order', $model->provider_order_id, false);
        return response()->json(['model' => $this->fullModel('ProviderOrderExtraCost', $model->id)], 200);
    }

    public function destroy($id) {
        $model = ProviderOrderExtraCost::find($id);
        ImageController::deleteModelImages($model);
        $model->delete();
        $this->sendDeleteModelNotification('ProviderOrderExtraCost', $model->id);
        return response(null);
    }
}
