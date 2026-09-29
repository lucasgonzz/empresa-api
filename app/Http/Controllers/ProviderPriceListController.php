<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\CommonLaravel\ImageController;
use App\Jobs\ProcessSetFinalPrices;
use App\Models\ProviderPriceList;
use Illuminate\Http\Request;

class ProviderPriceListController extends Controller
{

    public function index() {
        $models = ProviderPriceList::where('user_id', $this->userId())
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        $model = ProviderPriceList::create([
            'num'                   => $this->num('provider_price_lists', null, 'provider_id', $request->model_id),
            'name'                  => $request->name,
            'percentage'            => $request->percentage,
            'provider_id'           => $request->model_id,
            'temporal_id'           => $this->getTemporalId($request),
            // 'user_id'               => $this->userId(),
        ]);
        if (!is_null($request->model_id)) {
            $this->sendAddModelNotification('provider', $request->model_id);
        }
        return response()->json(['model' => $this->fullModel('ProviderPriceList', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('ProviderPriceList', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = ProviderPriceList::find($id);
        $last_percentage            = $model->percentage;
        $model->name                = $request->name;
        $model->percentage          = $request->percentage;
        $model->provider_id         = $this->get_model_id($request, 'provider_id');
        $model->save();
        $this->recalcular_precios_si_cambio_el_porcentaje($model, $last_percentage);
        $this->sendAddModelNotification('provider_price_list', $model->id);
        return response()->json(['model' => $this->fullModel('ProviderPriceList', $model->id)], 200);
    }

    /**
     * Si cambio el porcentaje de la lista, recalcula en segundo plano SOLO los articulos que usan
     * esta lista (mision recalculo-precios-motor-rapido, 28/9/2026).
     *
     * 🔴 El alcance es `provider_price_list_id`, no el proveedor entero como hasta hoy
     * (GeneralHelper::checkNewValuesForArticlesPrices con 'provider_id'). El porcentaje de una lista
     * del proveedor lo lee UNICAMENTE ArticleHelper::aplicar_margenes_de_proveedor_y_categoria(), y
     * solo para el articulo que tiene esa lista asignada (`$article->provider_price_list`).
     * Recalcular el resto del proveedor es trabajo que no puede mover un centavo, y en un proveedor
     * grande son horas de cola (ver ProviderController::recalcular_precios_si_corresponde()).
     *
     * La comparacion es suelta (`!=`), la misma de siempre: "10.00" de la base contra 10 del
     * formulario no es un cambio. El nombre y el proveedor de la lista no mueven precios.
     *
     * El `user_id` que se despacha es el del DUEÑO (`$this->userId()`), nunca el del empleado: la
     * lista no tiene `user_id` propio, y el productor filtra los articulos por el dueño.
     *
     * @param  \App\Models\ProviderPriceList $price_list       Lista ya guardada.
     * @param  mixed                         $last_percentage  Porcentaje antes de guardar.
     * @return bool  true si se despacho el recalculo.
     */
    protected function recalcular_precios_si_cambio_el_porcentaje($price_list, $last_percentage) {

        if ($last_percentage == $price_list->percentage) {
            return false;
        }

        /*
         * El nombre del PROVEEDOR como detalle, igual que el resto de los recalculos por proveedor:
         * es lo que el usuario reconoce en la pildora de procesos ("por un cambio en un proveedor ·
         * Rejovot"). Si la lista quedo sin proveedor, el detalle queda vacio y el recalculo sale igual.
         */
        $nombre_del_proveedor = GeneralHelper::nombre_del_proveedor('provider_id', $price_list->provider_id);

        ProcessSetFinalPrices::dispatch(
            $this->userId(),
            'provider_price_list_id',
            $price_list->id,
            false,
            'proveedor',
            $nombre_del_proveedor
        );

        return true;
    }

    public function destroy($id) {
        $model = ProviderPriceList::find($id);
        $model->delete();
        ImageController::deleteModelImages($model);
        $this->sendDeleteModelNotification('provider_price_list', $model->id);
        return response(null);
    }
}
