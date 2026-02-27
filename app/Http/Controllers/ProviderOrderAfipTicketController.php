<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\ProviderOrderAfipTicket;
use Illuminate\Http\Request;
use Carbon\Carbon;

class ProviderOrderAfipTicketController extends Controller
{

    public function index() {
        $models = ProviderOrderAfipTicket::where('user_id', $this->userId())
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        $issued_at = $request->issued_at;
        if ($issued_at == '') {
            $issued_at = Carbon::now();
        }

        $model = ProviderOrderAfipTicket::create([
            // 'num'                   => $this->num('provider_order_afip_tickets'),
            'code'                  => $request->code,
            'issued_at'             => $issued_at,
            // 'total_iva'             => $request->total_iva,
            'percepcion_iibb'       => $request->percepcion_iibb,
            'percepcion_iva'        => $request->percepcion_iva,
            'retencion_iibb'        => $request->retencion_iibb,
            'retencion_iva'         => $request->retencion_iva,
            'retencion_ganancias'   => $request->retencion_ganancias,
            'total'                 => $request->total,
            'provider_order_id'     => $request->model_id,
            'temporal_id'           => $this->getTemporalId($request),
            'user_id'               => $this->userId(),
        ]);

        $this->updateRelationsCreated('provider_order_afip_ticket', $model->id, $request->childrens);

        if (!is_null($request->model_id)) {
            $this->sendAddModelNotification('provider_order', $request->model_id);
        }

        $this->set_total_iva($model);

        return response()->json(['model' => $this->fullModel('ProviderOrderAfipTicket', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('ProviderOrderAfipTicket', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = ProviderOrderAfipTicket::find($id);
        $model->code                  = $request->code; 
        $model->issued_at             = $request->issued_at;
        // $model->total_iva             = $request->total_iva;

        $model->percepcion_iibb       = $request->percepcion_iibb;
        $model->percepcion_iva        = $request->percepcion_iva;
        $model->retencion_iibb        = $request->retencion_iibb;
        $model->retencion_iva         = $request->retencion_iva;
        $model->retencion_ganancias   = $request->retencion_ganancias;
        $model->total                 = $request->total;
        $model->provider_order_id     = $this->get_model_id($request, 'provider_order_id');
        $model->save();
        $this->sendAddModelNotification('provider_order_afip_ticket', $model->id);
        $this->set_total_iva($model);
        return response()->json(['model' => $this->fullModel('ProviderOrderAfipTicket', $model->id)], 200);
    }

    public function destroy($id) {
        $model = ProviderOrderAfipTicket::find($id);
        $model->delete();
        ImageController::deleteModelImages($model);
        $this->sendDeleteModelNotification('provider_order_afip_ticket', $model->id);
        return response(null);
    }

    function set_total_iva($model) {
        $total = 0;
        foreach ($model->provider_order_afip_ticket_ivas as $iva) {
            $total += (float)$iva->iva_importe;
        }
        $model->total_iva = $total;
        $model->save();
    }
}
