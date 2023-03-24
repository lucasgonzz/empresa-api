<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\AfipInformation;
use Illuminate\Http\Request;

class AfipInformationController extends Controller
{

    public function index() {
        $models = AfipInformation::where('user_id', $this->userId())
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        $model = AfipInformation::create([
            'num'                       => $this->num('afip_informations'),
            'iva_condition_id'          => $request->iva_condition_id,
            'razon_social'              => $request->razon_social,
            'domicilio_comercial'       => $request->domicilio_comercial,
            'cuit'                      => $request->cuit,
            'ingresos_brutos'           => $request->ingresos_brutos,
            'inicio_actividades'        => $request->inicio_actividades,
            'punto_venta'               => $request->punto_venta,
            'afip_ticket_production'    => $request->afip_ticket_production,
            'user_id'                   => $this->userId(),
        ]);
        $this->sendAddModelNotification('afip_information', $model->id);
        return response()->json(['model' => $this->fullModel('AfipInformation', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('AfipInformation', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = AfipInformation::find($id);
        $model->iva_condition_id          = $request->iva_condition_id;
        $model->razon_social              = $request->razon_social;
        $model->domicilio_comercial       = $request->domicilio_comercial;
        $model->cuit                      = $request->cuit;
        $model->ingresos_brutos           = $request->ingresos_brutos;
        $model->inicio_actividades        = $request->inicio_actividades;
        $model->punto_venta               = $request->punto_venta;
        $model->afip_ticket_production    = $request->afip_ticket_production;
        $model->save();
        $this->sendAddModelNotification('afip_information', $model->id);
        return response()->json(['model' => $this->fullModel('AfipInformation', $model->id)], 200);
    }

    public function destroy($id) {
        $model = AfipInformation::find($id);
        $model->delete();
        ImageController::deleteModelImages($model);
        $this->sendDeleteModelNotification('AfipInformation', $model->id);
        return response(null);
    }
}
