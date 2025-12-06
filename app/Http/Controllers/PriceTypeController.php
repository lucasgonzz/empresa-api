<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\CommonLaravel\ImageController;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\PriceTypeHelper;
use App\Jobs\ProcessSetFinalPrices;
use App\Models\Article;
use App\Models\PriceType;
use Illuminate\Http\Request;

class PriceTypeController extends Controller
{

    public function index() {
        $models = PriceType::where('user_id', $this->userId())
                            ->orderBy('position', 'ASC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        $model = PriceType::create([
            'num'                   => $this->num('price_types'),
            'name'                  => $request->name,
            'percentage'            => $request->percentage,
            'position'              => $request->position,
            'ocultar_al_publico'    => $request->ocultar_al_publico,
            'incluir_en_lista_de_precios_de_excel'    => $request->incluir_en_lista_de_precios_de_excel,
            'setear_precio_final'    => $request->setear_precio_final,
            'se_usa_en_tienda_nube'    => $request->se_usa_en_tienda_nube,
            'user_id'               => $this->userId(),
        ]);

        $this->agregar_a_articulos_existentes($model);

        $this->sendAddModelNotification('price_type', $model->id);

        $this->updateRelationsCreated('price_type', $model->id, $request->childrens);
        
        GeneralHelper::attachModels($model, 'categories', $request->categories, ['percentage']);
        GeneralHelper::attachModels($model, 'sub_categories', $request->sub_categories, ['percentage']);

        return response()->json(['model' => $this->fullModel('PriceType', $model->id)], 201);
    }  

    function agregar_a_articulos_existentes($price_type) {

        ProcessSetFinalPrices::dispatch($this->userId());

    }

    public function show($id) {
        return response()->json(['model' => $this->fullModel('PriceType', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = PriceType::find($id);
        $model->name                = $request->name;
        $model->percentage          = $request->percentage;
        $model->position            = $request->position;
        $model->ocultar_al_publico  = $request->ocultar_al_publico;
        $model->incluir_en_lista_de_precios_de_excel  = $request->incluir_en_lista_de_precios_de_excel;
        $model->setear_precio_final  = $request->setear_precio_final;
        $model->se_usa_en_tienda_nube  = $request->se_usa_en_tienda_nube;
        $model->save();

        GeneralHelper::attachModels($model, 'categories', $request->categories, ['percentage']);
        GeneralHelper::attachModels($model, 'sub_categories', $request->sub_categories, ['percentage']);
        
        $this->sendAddModelNotification('price_type', $model->id);

        PriceTypeHelper::check_recargos($model);

        return response()->json(['model' => $this->fullModel('PriceType', $model->id)], 200);
    }

    public function destroy($id) {
        $model = PriceType::find($id);

        // Eliminar relaciones con artículos
        $model->articles()->detach();
        
        $model->delete();
        ImageController::deleteModelImages($model);
        $this->sendDeleteModelNotification('PriceType', $model->id);
        return response(null);
    }
}
