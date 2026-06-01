<?php

namespace App\Http\Controllers\CommonLaravel;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\CommonLaravel\SearchController;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\MasiveUpdateHelper;
use App\Jobs\ProcessMasiveUpdateJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class UpdateController extends Controller
{
    /**
     * Encola una actualización masiva y registra criterios para historial y reversión.
     *
     * @param \Illuminate\Http\Request $request
     * @param string $model_name
     * @return \Illuminate\Http\JsonResponse
     */
    function update(Request $request, $model_name) {

        $models = [];
        $formated_model_name = GeneralHelper::getModelName($model_name);
        $from_filter = (boolean) $request->from_filter;

        if ($from_filter) {
            $search_ct = new SearchController();
            $res = $search_ct->search($request, $model_name, $request->filter_form, 0, true);
            $models = $res['models'];
            $used_filters = $res['used_filters'];
            $effective_filters = array_filter($used_filters, function ($filter) {
                return isset($filter['operator']) && $filter['operator'] != 'order_by';
            });
            if (count($effective_filters) == 0) {
                Log::info('Se interrumpio actualizacion: filtros vacios o no restrictivos (solo order_by).');
                return response()->json([
                    'message' => 'No se permite actualizar por filtro si no hay criterios de filtrado.',
                ], 422);
            }
        } else {
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

        if (count($models) >= 3000) {
            Log::info('NO se permitio actualizar los '.count($models).' '.$model_name);
            return response()->json(['message' => 'No se permitio actualizar '.count($models).' registros'], 422);
        }

        $resolved_models_id = [];
        foreach ($models as $model) {
            if ($model && isset($model->id)) {
                $resolved_models_id[] = $model->id;
            }
        }

        if (count($resolved_models_id) == 0) {
            return response()->json([
                'message' => 'No hay registros para actualizar',
            ], 422);
        }

        $criteria = [
            'from_filter' => $from_filter,
            'used_filters' => $used_filters,
            'update_form' => $request->update_form,
            'models_id' => $from_filter ? [] : $request->models_id,
            'resolved_models_id' => $resolved_models_id,
            'filter_form' => $from_filter ? $request->filter_form : [],
        ];

        $masive_update = MasiveUpdateHelper::create_pending_update(
            $this->userId(true),
            $this->userId(false),
            $model_name,
            $from_filter,
            $criteria
        );

        ProcessMasiveUpdateJob::dispatch($masive_update->id);

        Log::info('UpdateController: actualizacion masiva encolada', [
            'masive_update_id' => $masive_update->id,
            'model_name' => $model_name,
            'records_count' => count($resolved_models_id),
        ]);

        return response()->json([
            'message' => 'La actualización masiva se está procesando en segundo plano',
            'masive_update_id' => $masive_update->id,
            'queued_count' => count($resolved_models_id),
        ], 200);
    }
}
