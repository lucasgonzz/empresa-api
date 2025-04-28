<?php

namespace App\Http\Controllers;

use App\Models\VentaTerminadaCommission;
use Illuminate\Http\Request;

class VentaTerminadaCommissionController extends Controller
{

    public function index() {
        $models = VentaTerminadaCommission::where('user_id', $this->userId())
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        $model = VentaTerminadaCommission::create([
            'monto_fijo'            => $request->monto_fijo,
            'seller_id'             => $request->seller_id,
            'user_id'               => $this->userId(),
        ]);
        $this->sendAddModelNotification('VentaTerminadaCommission', $model->id);
        return response()->json(['model' => $this->fullModel('VentaTerminadaCommission', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('VentaTerminadaCommission', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = VentaTerminadaCommission::find($id);
        $model->monto_fijo               = $request->monto_fijo;
        $model->seller_id                = $request->seller_id;
        $model->save();
        $this->sendAddModelNotification('VentaTerminadaCommission', $model->id);
        return response()->json(['model' => $this->fullModel('VentaTerminadaCommission', $model->id)], 200);
    }

    public function destroy($id) {
        $model = VentaTerminadaCommission::find($id);
        $model->delete();
        ImageController::deleteModelImages($model);
        $this->sendDeleteModelNotification('VentaTerminadaCommission', $model->id);
        return response(null);
    }
}
