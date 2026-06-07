<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\UserConfiguration;
use Illuminate\Http\Request;

class UserConfigurationController extends Controller
{

    public function index() {
        $models = UserConfiguration::where('user_id', $this->userId())
                            ->withAll()
                            ->orderBy('created_at', 'DESC')
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    public function show($id) {
        return response()->json(['model' => $this->fullModel('UserConfiguration', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = UserConfiguration::find($id);
        $current_value = $model->iva_included;
        $model->show_articles_without_stock     = $request->show_articles_without_stock;
        $model->iva_included                    = $request->iva_included;
        $model->apply_price_type_in_services    = $request->apply_price_type_in_services;
        $model->save();
        GeneralHelper::checkNewValuesForArticlesPrices($this, $current_value, $request->iva_included);
        $this->sendAddModelNotification('configuration', $model->id);
        return response()->json(['model' => $this->fullModel('UserConfiguration', $model->id)], 200);
    }

    /**
     * Actualiza el ancho personalizado de los paneles izquierdo y derecho
     * del módulo de vender para el usuario autenticado.
     *
     * Busca la UserConfiguration del usuario autenticado y persiste
     * los anchos recibidos. No modifica el método update() existente
     * para no afectar el flujo de configuración general.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateVenderLayout(Request $request) {
        /* Recuperar la configuración del usuario autenticado */
        $model = UserConfiguration::where('user_id', $this->userId())->first();

        /* Persistir los anchos de panel recibidos desde el frontend */
        $model->vender_left_width  = $request->vender_left_width;
        $model->vender_right_width = $request->vender_right_width;
        $model->save();

        return response()->json(['model' => $this->fullModel('UserConfiguration', $model->id)], 200);
    }

    public function destroy($id) {
        $model = UserConfiguration::find($id);
        ImageController::deleteModelImages($model);
        $model->delete();
        $this->sendDeleteModelNotification('configuration', $model->id);
        return response(null);
    }
}
