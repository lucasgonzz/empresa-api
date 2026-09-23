<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\SearchController;
use App\Http\Controllers\Helpers\GeneralHelper;
use App\Http\Controllers\Helpers\currentAcount\CuentaCorrienteLock;
use App\Http\Controllers\Helpers\sale\RestoreSaleFromPapeleraHelper;
use App\Http\Controllers\Helpers\sale\SaleArticlesEagerLoadHelper;
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

        $query = $model_name::query()
            ->where('user_id', $this->userId())
            ->whereNotNull('deleted_at')
            ->withAll()
            ->withTrashed()
            ->orderBy('deleted_at', 'DESC');

        if ($_model_name === 'sale') {
            SaleArticlesEagerLoadHelper::apply_images_if_preferred($query, $this->userId());
        }

        $paginator = $query->paginate($per_page, ['*'], 'page', $page);

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

            // Dueño antes que stock (ver bloquear_cuentas_de_ventas()).
            if ($model_name === Sale::class) {
                CuentaCorrienteLock::bloquear('client', $model->client_id);
            }

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

        /** Clientes de las ventas del lote, resueltos antes de abrir la transacción. */
        $client_ids = $model_name === Sale::class
                        ? Sale::onlyTrashed()->where('user_id', $user_id)->whereIn('id', $ids)->whereNotNull('client_id')->distinct()->pluck('client_id')->all()
                        : [];

        DB::transaction(function () use ($model_name, $ids, $user_id, $client_ids) {

            $this->bloquear_cuentas_de_ventas($client_ids);

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

        /*
         * Los clientes de TODAS las ventas en papelera del comercio, no solo las que coinciden con el
         * filtro: el filtro lo resuelve la búsqueda por lotes adentro del bucle (y cada lote sale de
         * la papelera al restaurarse), así que no se puede saber de antemano cuáles van a ser. Es un
         * superconjunto a propósito: bloquear de más en una restauración masiva, que es rara, es
         * mejor que tomar un candado de cuenta DESPUÉS del stock y esperarse en círculo con una
         * edición de venta.
         */
        $client_ids = $model_name === Sale::class
                        ? Sale::onlyTrashed()->where('user_id', $user_id)->whereNotNull('client_id')->distinct()->pluck('client_id')->all()
                        : [];

        DB::transaction(function () use ($request, $_model_name, $search_controller, $model_name, $user_id, $client_ids) {

            $this->bloquear_cuentas_de_ventas($client_ids);

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
     * Candado de las cuentas corrientes de los clientes de las ventas que se van a restaurar, todos
     * juntos, en orden ascendente y AL PRINCIPIO de la transacción (misión
     * cuenta-corriente-carrera-y-velocidad, 23/9/2026). Restaurar una venta vuelve a descontar stock
     * y después la mete en la cuenta corriente; si el candado de la cuenta se tomara recién ahí
     * (CurrentAcountFromSaleHelper), el orden sería stock -> cuenta, el inverso de la edición de una
     * venta (cuenta -> stock), y dos requests así se pueden esperar en círculo. Ver CuentaCorrienteLock.
     *
     * @param  array<int,int>  $client_ids
     * @return void
     */
    protected function bloquear_cuentas_de_ventas($client_ids) {

        CuentaCorrienteLock::bloquear('client', $client_ids);
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
