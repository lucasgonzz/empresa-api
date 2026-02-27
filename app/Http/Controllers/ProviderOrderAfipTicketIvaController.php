<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\ProviderOrderAfipTicketIva;
use Illuminate\Http\Request;

class ProviderOrderAfipTicketIvaController extends Controller
{

    public function index() {
        $models = ProviderOrderAfipTicketIva::where('user_id', $this->userId())
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        $model = ProviderOrderAfipTicketIva::create([
            'provider_order_afip_ticket_id'     => $request->model_id,
            'iva_id'                            => $request->iva_id,
            'neto'                              => $request->neto,
            'iva_importe'                       => $request->iva_importe,
            'temporal_id'                       => $this->getTemporalId($request),
            // 'user_id'                           => $this->userId(),
        ]);
        $this->sendAddModelNotification('ProviderOrderAfipTicketIva', $model->id);
        return response()->json(['model' => $this->fullModel('ProviderOrderAfipTicketIva', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('ProviderOrderAfipTicketIva', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = ProviderOrderAfipTicketIva::find($id);
        $model->provider_order_afip_ticket_id       = $request->provider_order_afip_ticket_id;
        $model->iva_id                              = $request->iva_id;
        $model->neto                                = $request->neto;
        $model->iva_importe                         = $request->iva_importe;
        $model->save();
        $this->sendAddModelNotification('ProviderOrderAfipTicketIva', $model->id);
        return response()->json(['model' => $this->fullModel('ProviderOrderAfipTicketIva', $model->id)], 200);
    }

    public function destroy($id) {
        $model = ProviderOrderAfipTicketIva::find($id);
        ImageController::deleteModelImages($model);
        $model->delete();
        $this->sendDeleteModelNotification('ProviderOrderAfipTicketIva', $model->id);
        return response(null);
    }
}
