<?php

namespace App\Http\Controllers\CommonLaravel;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\CommonLaravel\ImageController;
use App\Http\Controllers\CommonLaravel\SearchController;
use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Services\Filter\FilterHistoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class DeleteController extends Controller
{
    function delete(Request $request, $model_name) {
        $models = [];
        $formated_model_name = GeneralHelper::getModelName($model_name);

        $used_filters = [];
        /**
         * Indica si la acción viene desde "aplicar filtros" (operación masiva por resultado).
         * En este caso, es crítico evitar ejecutar la operación si no hay filtros efectivos,
         * porque el search podría devolver todos los modelos del usuario.
         */
        $from_filter = (boolean) $request->from_filter;
        
        if ($from_filter) {
            $search_ct = new SearchController();
            $res = $search_ct->search($request, $model_name, $request->filter_form, 0, true);
            $models = $res['models'];
            $used_filters = $res['used_filters'];
            Log::info('used_filters de DeleteController:');
            Log::info($used_filters);
            /**
             * Filtro efectivo = cualquier criterio distinto a "order_by".
             * Si no hay filtros efectivos, el search devolverá todo (o casi todo) y es riesgoso.
             */
            $effective_filters = array_filter($used_filters, function ($filter) {
                return isset($filter['operator']) && $filter['operator'] != 'order_by';
            });
            /** Evita eliminación masiva por filtro vacío o solo ordenamiento. */
            if (count($effective_filters) == 0) {
                Log::info('Se interrumpio eliminado: filtros vacios o no restrictivos (solo order_by).');
                return response()->json([
                    'message' => 'No se permite eliminar por filtro si no hay criterios de filtrado.',
                ], 422);
            }
            // foreach($models as $model) {
            //     Log::info($model->name);
            // }
        } else {
            /**
             * Eliminación por selección manual: si no vienen IDs, no se debe hacer nada.
             * Esto evita que un payload inconsistente termine en un comportamiento inesperado.
             */
            if (!isset($request->models_id) || count($request->models_id) == 0) {
                Log::info('Se interrumpio eliminado: seleccion manual sin models_id.');
                return response()->json([
                    'message' => 'No se permite eliminar sin selección de registros.',
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
            // foreach($models as $model) {
            //     Log::info($model->name);
            // }
        }
        $models_response = [];
        
        $send_notification = true;
        if (count($models) > 300) {
            $send_notification = false;
        }
        
        if ($model_name == 'article') {
            /**
             * Seguridad extra: si el conjunto a eliminar coincide con TODOS los artículos activos,
             * se interrumpe para evitar un borrado total por algún edge-case (ej: filtros vacíos).
             *
             * Nota: se compara contra activos (misma base que usa SearchController para article).
             */
            $cantidad_articles_activos = Article::where('user_id', $this->userId())
                                                ->where('status', 'active')
                                                ->count();
            if ($cantidad_articles_activos == count($models)) {
                Log::info('Se interrumpio eliminado, eran todos los articulos');
                return response()->json([
                    'message' => 'No se permite eliminar todos los artículos.',
                ], 422);
            }
        }

        $eliminados = 0;
        foreach ($models as $model) {
            $controller_name = 'App\\Http\\Controllers\\'.explode('\\', $formated_model_name)[2].'Controller';
            $controller = new $controller_name();

            Log::info(Auth()->user()->name.' va a eliminar '.$model_name.' id: '.$model->id);

            if ($model->name) {
                Log::info('Nombre: '.$model->name);
            }
            
            if ($model_name == 'article') {
                $controller->destroy($model->id, $send_notification);
            } else {
                $controller->destroy($model->id);
            }

            $eliminados++;
            
        }


        if ($model_name == 'article') {
            FilterHistoryService::log_action([
                'user_id'             => $this->userId(true),
                'auth_user_id'        => $this->userId(false),
                'action'              => 'eliminacion',
                'model_name'          => 'article',
                'filtrados_count'     => count($models),
                'afectados_count'     => $eliminados,
                'used_filters'        => $used_filters,
            ]);
        }
        return response()->json(['models' => $models], 200);
    }
}
