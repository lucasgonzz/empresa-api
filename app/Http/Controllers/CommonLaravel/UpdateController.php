<?php

namespace App\Http\Controllers\CommonLaravel;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\CommonLaravel\SearchController;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Services\Filter\FilterHistoryService;
use App\Services\TiendaNube\TiendaNubeSyncArticleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class UpdateController extends Controller
{
    function update(Request $request, $model_name) {

        $models = [];
        $formated_model_name = GeneralHelper::getModelName($model_name);
        /**
         * Indica si la acción viene desde "aplicar filtros" (operación masiva por resultado).
         * En este caso, hay que evitar actualizar si los filtros están vacíos o no son restrictivos,
         * porque el search podría devolver todos los modelos del usuario.
         */
        $from_filter = (boolean) $request->from_filter;

        if ($from_filter) {
            $search_ct = new SearchController();
            $res = $search_ct->search($request, $model_name, $request->filter_form, 0, true);
            $models = $res['models'];
            $used_filters = $res['used_filters'];
            /**
             * Filtro efectivo = cualquier criterio distinto a "order_by".
             * Si no hay filtros efectivos, el search devolverá todo (o casi todo) y es riesgoso.
             */
            $effective_filters = array_filter($used_filters, function ($filter) {
                return isset($filter['operator']) && $filter['operator'] != 'order_by';
            });
            /** Evita actualización masiva por filtro vacío o solo ordenamiento. */
            if (count($effective_filters) == 0) {
                Log::info('Se interrumpio actualizacion: filtros vacios o no restrictivos (solo order_by).');
                return response()->json([
                    'message' => 'No se permite actualizar por filtro si no hay criterios de filtrado.',
                ], 422);
            }
        } else {
            /**
             * Actualización por selección manual: si no vienen IDs, no se debe hacer nada.
             * Esto evita que un payload inconsistente termine en una actualización masiva accidental.
             */
            if (!isset($request->models_id) || count($request->models_id) == 0) {
                Log::info('Se interrumpio actualizacion: seleccion manual sin models_id.');
                return response()->json([
                    'message' => 'No se permite actualizar sin selección de registros.',
                ], 422);
            }
            foreach ($request->models_id as $id) {
                $models[] = $formated_model_name::find($id);
            }
            $used_filters = [
                [
                    'key'       => 'Seleccion manual'
                ],
            ];
        }

        $models_response = [];
        // if (count($models) < 2) {
        if (count($models) < 3000) {


            $afectados = 0;

            foreach ($models as $model) {
                foreach ($request->update_form as $form) {
                    if ($form['type'] == 'number' && str_contains($form['key'], 'decrement') && $form['value'] != '') {
                        $value = $model->{substr($form['key'], 10)} * (float)$form['value'] / 100;
                        $model->{substr($form['key'], 10)} -= $value;
                        if ($form['round']) {
                            $model->{substr($form['key'], 10)} = round($model->{substr($form['key'], 10)}, 0, PHP_ROUND_HALF_UP);
                        }
                        $model->save();
                        $afectados++;


                        // $used_filters[] = [
                        //     'key'       => $form['key'],
                        //     'operator'  => 'disminuir',
                        //     'value'     => $form['value'],
                        //     'type'      => $form['type'],
                        // ];
                        // Log::info('Se disminuyo '.substr($form['key'], 10).' de '.$model->name.', quedo en '.$model->{substr($form['key'], 10)});
                    } else if ($form['type'] == 'number' && str_contains($form['key'], 'increment') && $form['value'] != '') {
                        $value = $model->{substr($form['key'], 10)} * (float)$form['value'] / 100;
                        $model->{substr($form['key'], 10)} += $value; 
                        if ($form['round']) {
                            $model->{substr($form['key'], 10)} = round($model->{substr($form['key'], 10)}, 0, PHP_ROUND_HALF_UP);
                        }
                        $model->save();
                        $afectados++;


                        // $used_filters[] = [
                        //     'key'       => $form['key'],
                        //     'operator'  => 'incrementar',
                        //     'value'     => $form['value'],
                        //     'type'      => $form['type'],
                        // ];
                        // Log::info('Se aumento '.substr($form['key'], 10).' de '.$model->name.', quedo en '.$model->{substr($form['key'], 10)});
                    } else if ($form['type'] == 'number' && str_contains($form['key'], 'set_') && $form['value'] != '') {
                        $model->{substr($form['key'], 4)} = (float)$form['value'];
                        $model->save();
                        $afectados++;
                        Log::info('Se seteo '.substr($form['key'], 4).' de '.$model->name.', quedo en '.$model->{substr($form['key'], 4)});


                        // $used_filters[] = [
                        //     'key'       => $form['key'],
                        //     'operator'  => 'setear',
                        //     'value'     => $form['value'],
                        //     'type'      => $form['type'],
                        // ];
                    } else if ($form['type'] == 'search' && str_contains($form['key'], '_id') && $form['value'] != '' && $form['value'] != 0) {
                        $model->{$form['key']} = $form['value'];
                        $model->save();
                        $afectados++;


                        // $used_filters[] = [
                        //     'key'       => $form['key'],
                        //     'operator'  => 'setear',
                        //     'value'     => $form['value'],
                        //     'type'      => $form['type'],
                        // ];
                        // Log::info('Se seteo '.$form['key'].' de '.$model->name.', quedo en '.$model->{$form['key']});
                    } else if ($form['type'] == 'select' && str_contains($form['key'], '_id') && $form['value'] != '' && $form['value'] != 0) {
                        $model->{$form['key']} = $form['value'];
                        $model->save();
                        $afectados++;


                        // $used_filters[] = [
                        //     'key'       => $form['key'],
                        //     'operator'  => 'setear',
                        //     'value'     => $form['value'],
                        //     'type'      => $form['type'],
                        // ];
                        // Log::info('Se seteo '.$form['key'].' de '.$model->name.', quedo en '.$model->{$form['key']});
                    }
                }
                if ($model_name == 'article') {
                    ArticleHelper::setFinalPrice($model);
                    TiendaNubeSyncArticleService::add_article_to_sync($model);
                }
                $models_response[] = $this->fullModel($model_name, $model->id);
            }

            if ($model_name == 'article') {
                FilterHistoryService::log_action([
                    'user_id'             => $this->userId(true),
                    'auth_user_id'        => $this->userId(false),
                    'action'              => 'actualizacion',
                    'model_name'          => 'article',
                    'filtrados_count'     => count($models),
                    'afectados_count'     => $afectados,
                    'used_filters'        => $used_filters,
                ]);
            }

            Log::info('se actualizaron '.count($models).' '.$model_name.' desde updateController');
        } else {
            Log::info('NO se permitio actualizar los '.count($models).' '.$model_name);
            return response()->json(['message' => 'No se permitio actualizar '.count($models).' registros'], 422);
        }
        return response()->json(['models' => $models_response], 200);
    }
}
