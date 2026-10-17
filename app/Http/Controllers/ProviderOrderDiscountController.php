<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Http\Controllers\Helpers\providerOrder\TenenciaDeLaCompraHelper;
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

    /**
     * 🔴 Tenencia (misión tenencia-compra-hermanos, 17/10/2026): ver
     * ProviderOrderExtraCostController::costo_del_dueno(). Null si es de otro comercio, no existe o
     * no es un id.
     *
     * @param  mixed  $id
     * @return ProviderOrderDiscount|null
     */
    protected function descuento_del_dueno($id) {
        return TenenciaDeLaCompraHelper::hijo(ProviderOrderDiscount::class, $id, $this->userId());
    }

    protected function no_encontrado() {
        return TenenciaDeLaCompraHelper::no_encontrado('El descuento');
    }

    public function store(Request $request) {

        if (TenenciaDeLaCompraHelper::compra_ajena_en_cuerpo($request->model_id, $this->userId())) {
            return TenenciaDeLaCompraHelper::no_encontrado('La compra');
        }

        $model = ProviderOrderDiscount::create([
            'user_id'                   => $this->userId(),
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

        $model = $this->descuento_del_dueno($id);

        if (is_null($model)) {
            return $this->no_encontrado();
        }

        return response()->json(['model' => $this->fullModel('ProviderOrderDiscount', $model->id)], 200);
    }

    public function update(Request $request, $id) {
        $model = $this->descuento_del_dueno($id);

        if (is_null($model)) {
            return $this->no_encontrado();
        }

        $model->description               = $request->description;
        $model->percentage                = $request->percentage;
        $model->monto                     = $request->monto;
        $model->save();
        $this->sendAddModelNotification('ProviderOrderDiscount', $model->id);
        return response()->json(['model' => $this->fullModel('ProviderOrderDiscount', $model->id)], 200);
    }

    public function destroy($id) {
        $model = $this->descuento_del_dueno($id);

        if (is_null($model)) {
            return $this->no_encontrado();
        }

        ImageController::deleteModelImages($model);
        $model->delete();
        $this->sendDeleteModelNotification('ProviderOrderDiscount', $model->id);
        return response(null);
    }
}
