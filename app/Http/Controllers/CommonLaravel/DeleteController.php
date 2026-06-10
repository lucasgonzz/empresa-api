<?php

namespace App\Http\Controllers\CommonLaravel;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\CommonLaravel\SearchController;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\DeleteModelsHelper;
use App\Jobs\ProcessDeleteModelsJob;
use App\Models\Article;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class DeleteController extends Controller
{
    /**
     * Elimina registros por selección manual o por filtro.
     * Si superan el umbral, encola la operación y notifica al finalizar.
     *
     * @param \Illuminate\Http\Request $request
     * @param string $model_name
     * @return \Illuminate\Http\JsonResponse
     */
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
        }

        /** IDs resueltos y válidos para eliminar. */
        $resolved_models_id = [];
        foreach ($models as $model) {
            if ($model && isset($model->id)) {
                $resolved_models_id[] = $model->id;
            }
        }

        if (count($resolved_models_id) == 0) {
            return response()->json([
                'message' => 'No hay registros para eliminar',
            ], 422);
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
            if ($cantidad_articles_activos == count($resolved_models_id)) {
                Log::info('Se interrumpio eliminado, eran todos los articulos');
                return response()->json([
                    'message' => 'No se permite eliminar todos los artículos.',
                ], 422);
            }
        }

        /** Usuario owner y autenticado para historial y notificaciones. */
        $owner_user_id = $this->userId(true);
        $auth_user_id = $this->userId(false);

        /**
         * Más de 20 registros: se encola y se notifica al usuario solicitante al finalizar.
         */
        if (count($resolved_models_id) > DeleteModelsHelper::BACKGROUND_THRESHOLD) {
            ProcessDeleteModelsJob::dispatch(
                $model_name,
                $resolved_models_id,
                $owner_user_id,
                $auth_user_id,
                $used_filters
            );

            Log::info('DeleteController: eliminacion masiva encolada', [
                'model_name' => $model_name,
                'records_count' => count($resolved_models_id),
                'owner_user_id' => $owner_user_id,
                'auth_user_id' => $auth_user_id,
            ]);

            return response()->json([
                'message' => 'La eliminación se está procesando en segundo plano',
                'queued' => true,
                'queued_count' => count($resolved_models_id),
            ], 200);
        }

        /** Eliminación síncrona para lotes pequeños (comportamiento previo). */
        $result = DeleteModelsHelper::process_delete($model_name, $resolved_models_id, false);

        if ($model_name == 'article') {
            DeleteModelsHelper::log_article_filter_history(
                $owner_user_id,
                $auth_user_id,
                $used_filters,
                $result['total_count'],
                $result['deleted_count']
            );
        }

        return response()->json(['models' => $result['deleted_models']], 200);
    }
}
