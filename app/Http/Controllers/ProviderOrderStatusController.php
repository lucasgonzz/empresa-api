<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\ProviderOrderStatus;
use Illuminate\Http\Request;

class ProviderOrderStatusController extends Controller
{

    public function index() {
        $models = ProviderOrderStatus::orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        $model = ProviderOrderStatus::create([
            'num'                   => $this->num('provider_order_statuses'),
            'name'                  => $request->name,
            'user_id'               => $this->userId(),
        ]);
        $this->sendAddModelNotification('ProviderOrderStatus', $model->id);
        return response()->json(['model' => $this->fullModel('ProviderOrderStatus', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('ProviderOrderStatus', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = ProviderOrderStatus::find($id);
        $model->name                = $request->name;
        $model->save();
        $this->sendAddModelNotification('ProviderOrderStatus', $model->id);
        return response()->json(['model' => $this->fullModel('ProviderOrderStatus', $model->id)], 200);
    }

    public function destroy($id) {
        $model = ProviderOrderStatus::find($id);
        $model->delete();
        ImageController::deleteModelImages($model);
        $this->sendDeleteModelNotification('ProviderOrderStatus', $model->id);
        return response(null);
    }
}
