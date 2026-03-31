<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\SearchController;
use App\Http\Controllers\Helpers\GeneralHelper;
use App\Http\Controllers\Helpers\sale\RestoreSaleFromPapeleraHelper;
use App\Models\Sale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PapeleraController extends Controller
{
    /**
     * Listado paginado de registros en papelera (soft delete), del usuario actual, más recientes primero.
     *
     * Query: page (default 1), per_page (default 25, rango 1–200). Respuesta: models como LengthAwarePaginator (data, total, last_page, etc.).
     *
     * @param Request $request
     * @param string $_model_name Convención de ruta del front (ej. article, sale).
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request, $_model_name)
    {
        $model_name = GeneralHelper::getModelName($_model_name);

        $page = max(1, (int) $request->query('page', 1));
        $per_page = (int) $request->query('per_page', 25);
        if ($per_page < 1) {
            $per_page = 25;
        }
        if ($per_page > 200) {
            $per_page = 200;
        }

        $paginator = $model_name::query()
            ->where('user_id', $this->userId())
            ->whereNotNull('deleted_at')
            ->withAll()
            ->withTrashed()
            ->orderBy('deleted_at', 'DESC')
            ->paginate($per_page, ['*'], 'page', $page);

        return response()->json(['models' => $paginator], 200);
    }

    /**
     * Restaura un registro eliminado (soft delete). Para Sale reaplica efectos revertidos en destroy.
     *
     * @param string $_model_name Nombre de modelo en convención del front (p. ej. "sale").
     * @param int|string $model_id Id del registro en papelera.
     * @return \Illuminate\Http\Response
     */
    function restaurar($_model_name, $model_id) {

        $model_name = GeneralHelper::getModelName($_model_name);

        $model = $model_name::where('id', $model_id)
                            ->where('user_id', $this->userId())
                            ->withTrashed()
                            ->first();

        if (!$model || is_null($model->deleted_at)) {
            return response(null, 200);
        }

        DB::transaction(function () use ($model, $model_name) {
            $this->aplicar_restauracion_soft_delete($model, $model_name);
        });

        return response(null, 200);
    }

    /**
     * Restaura en una sola transacción varios registros eliminados del mismo modelo.
     *
     * @param Request $request Debe incluir ids: int[].
     * @param string $_model_name Convención del front (p. ej. "article", "sale").
     * @return \Illuminate\Http\Response
     */
    function restaurar_lote(Request $request, $_model_name) {

        $model_name = GeneralHelper::getModelName($_model_name);
        $ids = $request->input('ids', []);

        if (!is_array($ids) || count($ids) === 0) {
            return response(null, 200);
        }

        $user_id = $this->userId();

        DB::transaction(function () use ($model_name, $ids, $user_id) {

            foreach ($ids as $model_id) {

                $model = $model_name::where('id', $model_id)
                    ->where('user_id', $user_id)
                    ->withTrashed()
                    ->first();

                if (!$model || is_null($model->deleted_at)) {
                    continue;
                }

                $this->aplicar_restauracion_soft_delete($model, $model_name);
            }
        });

        return response(null, 200);
    }

    /**
     * Restaura todos los registros en papelera que coinciden con los mismos filtros que la búsqueda (todas las páginas).
     *
     * @param Request $request filters (igual que POST search), papelera: true se fuerza en servidor.
     * @param string $_model_name article, sale, etc.
     * @return \Illuminate\Http\Response
     */
    public function restaurar_filtrados(Request $request, $_model_name)
    {
        $model_name = GeneralHelper::getModelName($_model_name);
        /**
         * Procesamiento por lotes para evitar cargar miles de filas en memoria.
         * Siempre se consulta página 1: al restaurar un lote, esos registros salen
         * del conjunto "papelera", por lo que la siguiente página 1 trae el siguiente bloque.
         */
        $batch_per_page = 200;
        $request->merge([
            'papelera' => true,
            'per_page' => $batch_per_page,
        ]);

        /** @var SearchController $search_controller */
        $search_controller = app(SearchController::class);

        $user_id = $this->userId();

        DB::transaction(function () use ($request, $_model_name, $search_controller, $model_name, $user_id) {
            while (true) {
                // Forzamos page=1 en cada vuelta para drenar la papelera en bloques.
                $request->merge(['page' => 1]);
                $models_paginator = $search_controller->search($request, $_model_name, null, 1, false, true);
                $models = $models_paginator->items();

                if (!count($models)) {
                    break;
                }

                foreach ($models as $model) {
                    if (is_null($model->deleted_at)) {
                        continue;
                    }
                    if ((int) $model->user_id !== (int) $user_id) {
                        continue;
                    }
                    $this->aplicar_restauracion_soft_delete($model, $model_name);
                }
            }
        });

        return response(null, 200);
    }

    /**
     * Quit soft delete y, si es venta, reaplica stock / compras / C.C. / comisiones.
     *
     * @param \Illuminate\Database\Eloquent\Model $model Instancia trashed encontrada.
     * @param string $model_name FQCN del modelo.
     * @return void
     */
    protected function aplicar_restauracion_soft_delete($model, string $model_name) {

        $model->restore();

        if ($model_name === Sale::class) {
            RestoreSaleFromPapeleraHelper::run($model);
        }
    }
}
