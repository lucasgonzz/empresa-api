<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\PromocionVinotecaCommission;
use Illuminate\Http\Request;

class PromocionVinotecaCommissionController extends Controller
{

    public function index() {
        $models = PromocionVinotecaCommission::where('user_id', $this->userId())
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        $model = PromocionVinotecaCommission::create([
            'monto_fijo'            => $request->monto_fijo,
            'seller_id'             => $request->seller_id,
            'user_id'               => $this->userId(),
        ]);
        $this->sendAddModelNotification('PromocionVinotecaCommission', $model->id);
        return response()->json(['model' => $this->fullModel('PromocionVinotecaCommission', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('PromocionVinotecaCommission', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = PromocionVinotecaCommission::find($id);
        $model->monto_fijo               = $request->monto_fijo;
        $model->seller_id                = $request->seller_id;
        $model->save();
        $this->sendAddModelNotification('PromocionVinotecaCommission', $model->id);
        return response()->json(['model' => $this->fullModel('PromocionVinotecaCommission', $model->id)], 200);
    }

    public function destroy($id) {
        $model = PromocionVinotecaCommission::find($id);
        ImageController::deleteModelImages($model);
        $model->delete();
        $this->sendDeleteModelNotification('PromocionVinotecaCommission', $model->id);
        return response(null);
    }
}
