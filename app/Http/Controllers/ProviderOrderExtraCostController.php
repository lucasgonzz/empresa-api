<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Http\Controllers\Helpers\providerOrder\TenenciaDeLaCompraHelper;
use App\Models\ProviderOrderExtraCost;
use Illuminate\Http\Request;

class ProviderOrderExtraCostController extends Controller
{

    /**
     * 🔴 Tenencia (misión tenencia-compra-hermanos, 17/10/2026): el costo extra se resuelve por su
     * `user_id` (marcado desde el alta, para el que todavía no cuelga de nada) y, si no lo tiene, por
     * el de su compra. Null si es de otro comercio, no existe o no es un id.
     *
     * @param  mixed  $id
     * @return ProviderOrderExtraCost|null
     */
    protected function costo_del_dueno($id) {
        return TenenciaDeLaCompraHelper::hijo(ProviderOrderExtraCost::class, $id, $this->userId());
    }

    protected function no_encontrado() {
        return TenenciaDeLaCompraHelper::no_encontrado('El costo extra');
    }

    public function store(Request $request) {

        if (TenenciaDeLaCompraHelper::compra_ajena_en_cuerpo($request->model_id, $this->userId())) {
            return TenenciaDeLaCompraHelper::no_encontrado('La compra');
        }

        $model = ProviderOrderExtraCost::create([
            'user_id'                   => $this->userId(),
            'description'               => $request->description,
            'value'                     => $request->value,
            'provider_order_id'         => $request->model_id,
            'temporal_id'               => $this->getTemporalId($request),
            // Naturaleza contable del costo extra (Prompt 264: transporte, seguro,
            // arancel_importacion, otro). Si viene tipado, se prorratea y materializa como
            // recargo de artículo al procesar la orden (ver NewProviderOrderHelper).
            'tipo'                      => $request->tipo,
            'facturado'                 => $request->facturado,
            'iva_id'                    => $request->iva_id,
            'en_factura_compra'         => $request->en_factura_compra,
            'emisor_cuit'               => $request->emisor_cuit,
            'emisor_razon_social'       => $request->emisor_razon_social,
        ]);
        if (!is_null($request->model_id)) {
            $this->sendAddModelNotification('provider_order', $model->provider_order_id, false);
        }
        return response()->json(['model' => $this->fullModel('ProviderOrderExtraCost', $model->id)], 201);
    }  

    public function show($id) {

        $model = $this->costo_del_dueno($id);

        if (is_null($model)) {
            return $this->no_encontrado();
        }

        return response()->json(['model' => $this->fullModel('ProviderOrderExtraCost', $model->id)], 200);
    }

    public function update(Request $request, $id) {
        $model = $this->costo_del_dueno($id);

        if (is_null($model)) {
            return $this->no_encontrado();
        }

        $model->description          = $request->description;
        $model->value                = $request->value;
        // Naturaleza contable del costo extra (Prompt 264). Solo se pisa si el request lo manda
        // explícitamente, para no perder el tipo cargado si el frontend no lo envía en un update
        // parcial.
        if (!is_null($request->tipo)) {
            $model->tipo = $request->tipo;
        }
        $model->facturado             = $request->facturado;
        $model->iva_id                = $request->iva_id;
        $model->en_factura_compra     = $request->en_factura_compra;
        $model->emisor_cuit           = $request->emisor_cuit;
        $model->emisor_razon_social   = $request->emisor_razon_social;
        $model->save();
        $this->sendAddModelNotification('provider_order', $model->provider_order_id, false);
        return response()->json(['model' => $this->fullModel('ProviderOrderExtraCost', $model->id)], 200);
    }

    public function destroy($id) {
        $model = $this->costo_del_dueno($id);

        if (is_null($model)) {
            return $this->no_encontrado();
        }

        ImageController::deleteModelImages($model);
        $model->delete();
        $this->sendDeleteModelNotification('ProviderOrderExtraCost', $model->id);
        return response(null);
    }
}
