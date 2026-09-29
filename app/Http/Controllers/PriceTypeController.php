<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\CommonLaravel\ImageController;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Models\ArticleTicketDesign;
use App\Http\Controllers\Helpers\PriceTypeHelper;
use App\Jobs\ProcessSetFinalPrices;
use App\Models\Article;
use App\Models\PriceType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

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
        /**
         * Array de notificaciones para devolver al frontend en respuestas exitosas.
         *
         * Se usa como feedback inmediato cuando la creación de un price_type dispara el recálculo masivo
         * en background (queue).
         *
         * @var array<int, array{message:string,type:string}>
         */
        $notifications = [];

        $model = PriceType::create([
            'num'                   => $this->num('price_types'),
            'name'                  => $request->name,
            'percentage'            => $request->percentage,
            'apply_percentage_on_existing_articles' => 1,
            // 'apply_percentage_on_existing_articles' => $request->apply_percentage_on_existing_articles,
            'update_existing_articles_percentage_mode' => $request->update_existing_articles_percentage_mode ? $request->update_existing_articles_percentage_mode : 'none',
            'position'              => $request->position,
            'ocultar_al_publico'    => $request->ocultar_al_publico,
            'incluir_en_lista_de_precios_de_excel'    => $request->incluir_en_lista_de_precios_de_excel,
            'setear_precio_final'    => $request->setear_precio_final,
            'se_usa_en_tienda_nube'    => $request->se_usa_en_tienda_nube,
            'se_usa_en_ml'    => $request->se_usa_en_ml,
            'user_id'               => $this->userId(),
        ]);

        if ($this->user()->listas_de_precio) {
            // Solo aplica el nuevo tipo de precio a artículos existentes cuando el usuario lo pidió.
            $this->agregar_a_articulos_existentes($model, $request);

            // Feedback inmediato: el recálculo se ejecuta en segundo plano y puede demorar.
            $notifications[] = [
                'message' => 'Se inició la actualización de precios en segundo plano. Te avisaremos cuando termine.',
                'type'    => 'info',
            ];
        }


        $this->sendAddModelNotification('price_type', $model->id);

        /*
            Lista nueva -> su diseño de etiquetas de góndola (misión disenos-etiquetas-gondola,
            29/9/2026). Lo crea PriceTypeObserver::created() en el PriceType::create() de arriba,
            así también lo reciben las listas de la importación de clientes y de la demo. Acá solo
            se avisa a las otras sesiones del negocio, que es algo que un observer no puede hacer
            (sendAddModelNotification es del Controller). Un error acá no rompe el alta.
        */
        try {
            $diseno_de_la_lista = ArticleTicketDesign::where('user_id', $model->user_id)
                                                        ->where('price_type_id', $model->id)
                                                        ->first();

            if (!is_null($diseno_de_la_lista)) {
                $this->sendAddModelNotification('article_ticket_design', $diseno_de_la_lista->id);
            }
        } catch (\Throwable $e) {
            Log::warning('PriceTypeController@store: no se pudo avisar el diseño de etiquetas de la lista '.$model->id.': '.$e->getMessage());
        }

        $this->updateRelationsCreated('price_type', $model->id, $request->childrens);
        
        GeneralHelper::attachModels($model, 'categories', $request->categories, ['percentage']);
        GeneralHelper::attachModels($model, 'sub_categories', $request->sub_categories, ['percentage']);

        return response()->json([
            'model' => $this->fullModel('PriceType', $model->id),
            'notifications' => $notifications,
        ], 201);
    }  

    /**
     * Decide si dispara el recálculo masivo al crear un tipo de precio.
     *
     * @param PriceType $price_type
     * @param Request $request
     * @return void
     */
    function agregar_a_articulos_existentes($price_type, $request) {
        // Flag persistente que indica si corresponde aplicar el nuevo tipo a artículos existentes.
        // $apply_percentage_on_existing_articles = (bool) $price_type->apply_percentage_on_existing_articles;

        // Permite compatibilidad con request explícito si viene seteado desde frontend.
        // if (!is_null($request->apply_percentage_on_existing_articles)) {
        //     $apply_percentage_on_existing_articles = (bool) $request->apply_percentage_on_existing_articles;
        // }

        // if ($apply_percentage_on_existing_articles) {
            ProcessSetFinalPrices::dispatch($this->userId(), null, null, false, 'tipo_de_precio');
        // }

    }

    public function show($id) {
        return response()->json(['model' => $this->fullModel('PriceType', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = PriceType::find($id);
        // Mantiene el porcentaje previo para decidir actualización selectiva del pivot.
        $old_percentage = $model->percentage;
        $model->name                = $request->name;
        $model->percentage          = $request->percentage;
        // apply_percentage_on_existing_articles solo tiene sentido en el alta; no se reescribe en update.
        $model->update_existing_articles_percentage_mode = $request->update_existing_articles_percentage_mode ? $request->update_existing_articles_percentage_mode : 'none';
        $model->position            = $request->position;
        $model->ocultar_al_publico  = $request->ocultar_al_publico;
        $model->incluir_en_lista_de_precios_de_excel  = $request->incluir_en_lista_de_precios_de_excel;
        $model->setear_precio_final  = $request->setear_precio_final;
        $model->se_usa_en_tienda_nube  = $request->se_usa_en_tienda_nube;
        $model->se_usa_en_ml  = $request->se_usa_en_ml;
        
        $model->save();

        // Solo sincroniza pivots cuando realmente cambia el porcentaje por defecto.
        if ((string) $old_percentage !== (string) $model->percentage) {
            Log::info('Cambio el percentage de '.$model->name);
            PriceTypeHelper::sync_existing_articles_percentage(
                $model,
                $old_percentage,
                $model->update_existing_articles_percentage_mode
            );
        }

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
