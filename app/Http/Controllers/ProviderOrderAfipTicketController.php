<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\ProviderOrderAfipTicket;
use Illuminate\Http\Request;

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
        $model = ProviderOrderAfipTicket::create([
            // 'num'                   => $this->num('provider_order_afip_tickets'),
            'code'                  => $request->code,
            'issued_at'             => $request->issued_at,
            'total_iva'             => $request->total_iva,
            'total'                 => $request->total,
            'provider_order_id'     => $request->model_id,
            'temporal_id'           => $this->getTemporalId($request),
        ]);
        if (!is_null($request->model_id)) {
            $this->sendAddModelNotification('provider_order', $request->model_id);
        }
        return response()->json(['model' => $this->fullModel('ProviderOrderAfipTicket', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('ProviderOrderAfipTicket', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = ProviderOrderAfipTicket::find($id);
        $model->code                  = $request->code; 
        $model->issued_at             = $request->issued_at;
        $model->total_iva             = $request->total_iva;
        $model->total                 = $request->total;
        $model->provider_order_id     = $request->model_id;
        $model->save();
        $this->sendAddModelNotification('provider_order_afip_ticket', $model->id);
        return response()->json(['model' => $this->fullModel('ProviderOrderAfipTicket', $model->id)], 200);
    }

    public function destroy($id) {
        $model = ProviderOrderAfipTicket::find($id);
        $model->delete();
        ImageController::deleteModelImages($model);
        $this->sendDeleteModelNotification('provider_order_afip_ticket', $model->id);
        return response(null);
    }
}
