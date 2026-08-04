<?php

namespace App\Http\Controllers;

use App\Jobs\RollbackArticleImportHistory;
use App\Models\Article;
use App\Models\ArticleImportResult;
use App\Models\ImportConflict;
use App\Models\ImportHistory;
use Illuminate\Http\Request;

class ImportHistoryController extends Controller
{
    /**
     * Encola un rollback de importación para ejecutar en background.
     *
     * @param int $import_history_id
     * @return \Illuminate\Http\JsonResponse
     */
    function rollback($import_history_id) {
        /**
         * Buscamos el historial por id y usuario autenticado para evitar
         * que un usuario pueda revertir importaciones ajenas.
         */
        $import_history = ImportHistory::where('id', $import_history_id)
                                        ->where('user_id', $this->userId())
                                        ->first();

        if (is_null($import_history)) {
            return response()->json([
                'message' => 'No se encontro la importacion solicitada',
            ], 404);
        }

        /**
         * Bloqueamos rollback si la importación está activa para evitar
         * inconsistencias entre chunks en proceso y datos restaurados.
         */
        if (in_array($import_history->status, ['en_preparacion', 'en_proceso'])) {
            return response()->json([
                'message' => 'No se puede revertir una importacion en curso',
            ], 409);
        }

        /*
         * Mensajes especificos segun el caso, para que el usuario entienda que
         * paso (no un 409 pelado). El bloqueo real que cierra la carrera es el
         * UPDATE condicional de abajo -- esto es solo para dar un mensaje mejor
         * en el caso comun de un solo click de mas (grupo 305, prompt 02).
         */
        if ($import_history->rollback_status == 'encolado') {
            return response()->json([
                'message' => 'Ya hay una reversion en curso para esta importacion',
            ], 409);
        }

        if ($import_history->rollback_status == 'revertida' || !is_null($import_history->rolled_back_at)) {
            return response()->json([
                'message' => 'Esta importacion ya fue revertida',
            ], 409);
        }

        /*
         * Compare-and-swap: la condicion del where es parte del UPDATE, asi que dos
         * requests simultaneos no pueden encolar dos jobs. El primero deja la fila en
         * 'encolado' y el segundo actualiza 0 filas. Un if() antes del update no
         * alcanza: entre el if y el dispatch hay una ventana real (grupo 305, prompt 02).
         */
        $marcados = ImportHistory::where('id', $import_history->id)
                        ->where('user_id', $this->userId())
                        ->whereNull('rolled_back_at')
                        ->where(function ($query) {
                            $query->whereNull('rollback_status')
                                  ->orWhere('rollback_status', 'fallido');
                        })
                        ->update([
                            'rollback_status'       => 'encolado',
                            'rollback_requested_at' => now(),
                            'rollback_employee_id'  => $this->userId(false),
                            'rollback_error'        => null,
                        ]);

        if ($marcados === 0) {
            return response()->json([
                'message' => 'Esta importacion ya fue revertida o tiene una reversion en curso',
            ], 409);
        }

        /**
         * Pasamos el id del usuario autenticado (no el user_id del propio
         * historial) para que el guard del job compare dos valores de
         * origen distinto y no sea una comparacion tautologica.
         */
        RollbackArticleImportHistory::dispatch($import_history->id, $this->userId());

        return response()->json([
            'queued' => true,
            'message' => 'Rollback encolado correctamente',
        ], 202);
    }

    function index($model_name) {
        $models = ImportHistory::where('user_id', $this->userId())
                                ->where('model_name', $model_name)
                                ->orderBy('id', 'DESC')
                                ->with('chunks.article_import_result_observations')
                                ->take(10)
                                ->get();

        /*
         * matching_counts_json se guarda como texto plano (json_encode) en BD, igual que
         * el resto de columnas JSON del repo (ver ArticleImportHelper). Se decodifica acá
         * para que el frontend reciba un array ya parseado en vez de un string JSON
         * anidado. filas_ambiguas e identificadores_descartados ya viajan tal cual porque
         * son columnas enteras (grupo 232, prompt 03; la UI que los consuma es otro prompt).
         */
        $models->each(function ($model) {
            $model->matching_counts_json = is_null($model->matching_counts_json)
                ? null
                : json_decode($model->matching_counts_json, true);

            /* Mismo criterio y mismo lugar que MasiveUpdateController::index() (grupo 305, prompt 02). */
            $model->can_revert = $model->puede_revertirse();
        });

        return response()->json(['models' => $models], 200);
    }

    /**
     * Devuelve los chunks (ArticleImportResult) de un historial de importacion.
     * Verifica que el historial pertenezca al usuario autenticado antes de
     * listar sus chunks, para no exponer datos de importaciones ajenas
     * (grupo 240, prompt 01).
     *
     * @param int $import_history_id ID del historial de importacion.
     * @return \Illuminate\Http\JsonResponse
     */
    function chunks($import_history_id) {
        /* Confirmamos que el historial exista y sea del usuario autenticado. */
        $import_history = ImportHistory::where('id', $import_history_id)
                            ->where('user_id', $this->userId())
                            ->first();

        if (is_null($import_history)) {
            return response()->json(['message' => 'No se encontro la importacion'], 404);
        }

        $models = ArticleImportResult::where('import_history_id', $import_history_id)
                                    ->with('article_import_result_observations')
                                    ->get();

        return response()->json(['models' => $models], 200);
    }

    /**
     * Devuelve los articulos actualizados de un chunk (ArticleImportResult).
     * Filtra por el historial padre para garantizar que el chunk pertenezca
     * a una importacion del usuario autenticado (grupo 240, prompt 01).
     *
     * @param int $import_result_id ID del ArticleImportResult (chunk).
     * @return \Illuminate\Http\JsonResponse
     */
    function updated_models($import_result_id) {
        /*
         * Filtramos por import_history_id perteneciente al usuario autenticado
         * mediante subconsulta, sin necesidad de definir una relacion nueva.
         */
        $model = ArticleImportResult::where('id', $import_result_id)
                            ->whereIn('import_history_id', function ($query) {
                                $query->select('id')
                                    ->from('import_histories')
                                    ->where('user_id', $this->userId());
                            })
                            ->with('articulos_actualizados')
                            ->first();
        return response()->json(['model' => $model], 200);
    }

    /**
     * Devuelve los articulos creados de un chunk (ArticleImportResult).
     * Filtra por el historial padre para garantizar que el chunk pertenezca
     * a una importacion del usuario autenticado (grupo 240, prompt 01).
     *
     * @param int $import_result_id ID del ArticleImportResult (chunk).
     * @return \Illuminate\Http\JsonResponse
     */
    function created_models($import_result_id) {
        /*
         * Filtramos por import_history_id perteneciente al usuario autenticado
         * mediante subconsulta, sin necesidad de definir una relacion nueva.
         */
        $model = ArticleImportResult::where('id', $import_result_id)
                            ->whereIn('import_history_id', function ($query) {
                                $query->select('id')
                                    ->from('import_histories')
                                    ->where('user_id', $this->userId());
                            })
                            ->with('articulos_creados')
                            ->first();

        return response()->json(['model' => $model], 200);
    }

    /**
     * Devuelve la lista de artículos creados con código repetido para un ImportHistory dado.
     * Recorre todos los chunks del historial y recolecta los IDs almacenados en cada
     * ArticleImportResult.created_with_repeated_code_ids, luego trae los artículos de la BD.
     *
     * Acotada con limit/offset (grupo 291, prompt 05): antes traía TODOS los ids sin
     * límite. La recolección de ids en sí no se toca (el ->get() de ArticleImportResult
     * es chico: como mucho unos pocos chunks por historial), lo que se acota es el
     * whereIn final contra articles, que era lo que podía crecer sin techo.
     *
     * @param int                       $import_history_id ID del historial de importación.
     * @param \Illuminate\Http\Request  $request            query: limit (default 50, tope 200), offset (default 0).
     * @return \Illuminate\Http\JsonResponse { articles: [...], total: N }
     */
    function repeated_code_articles($import_history_id, Request $request) {
        /*
         * Confirmamos que el historial exista y sea del usuario autenticado
         * antes de recolectar articulos, para no exponer datos de
         * importaciones ajenas (grupo 240, prompt 01).
         */
        $import_history = ImportHistory::where('id', $import_history_id)
                            ->where('user_id', $this->userId())
                            ->first();

        if (is_null($import_history)) {
            return response()->json(['message' => 'No se encontro la importacion'], 404);
        }

        $limit = (int) $request->query('limit', 50);
        if ($limit <= 0) {
            $limit = 50;
        }
        if ($limit > 200) {
            $limit = 200;
        }

        $offset = (int) $request->query('offset', 0);
        if ($offset < 0) {
            $offset = 0;
        }

        /* Recolectar todos los IDs de artículos con código repetido de los chunks. */
        $all_ids = [];

        ArticleImportResult::where('import_history_id', $import_history_id)
            ->whereNotNull('created_with_repeated_code_ids')
            ->get()
            ->each(function ($chunk) use (&$all_ids) {
                /* created_with_repeated_code_ids ya se castea a array en el modelo. */
                $ids = $chunk->created_with_repeated_code_ids;

                if (is_array($ids)) {
                    foreach ($ids as $id) {
                        $all_ids[] = (int) $id;
                    }
                }
            });

        if (empty($all_ids)) {
            return response()->json(['articles' => [], 'total' => 0], 200);
        }

        $unique_ids = array_unique($all_ids);

        /* Traer los artículos con los datos mínimos para mostrar en la lista, acotados. */
        $articles = Article::whereIn('id', $unique_ids)
            ->select('id', 'name', 'bar_code', 'provider_code')
            ->orderBy('id', 'ASC')
            ->offset($offset)
            ->limit($limit)
            ->get();

        return response()->json([
            'articles' => $articles,
            'total'    => count($unique_ids),
        ], 200);
    }

    /**
     * Devuelve los conflictos de una importacion (identificadores ambiguos, placeholders
     * descartados y filas sin identificador), paginados y agrupados por tipo/campo para
     * el encabezado del modal. Filtra por user_id para que un usuario no pueda leer los
     * conflictos de otro (prompt 02, grupo 229; UI en prompt 05).
     *
     * Acotada con tipo/limit/offset (grupo 291, prompt 05): antes traía TODOS los
     * conflictos del historial sin límite y armaba el resumen iterando la colección
     * entera en PHP. Con Excel grandes y códigos repetidos, `fila_sobrescrita` se
     * registra una vez por cada fila pisada — pueden ser miles de filas para pintar 5
     * en el modal. Ahora el resumen y el total se calculan con agregados SQL.
     *
     * @param int                       $import_history_id ID del historial de importacion.
     * @param \Illuminate\Http\Request  $request            query: tipo, limit (default 50, tope 200), offset (default 0).
     * @return \Illuminate\Http\JsonResponse
     */
    function conflicts($import_history_id, Request $request) {

        /* Se filtra por el usuario autenticado para no exponer conflictos de otro owner/empleado. */
        $import_history = ImportHistory::where('id', $import_history_id)
                            ->where('user_id', $this->userId())
                            ->first();

        if (is_null($import_history)) {
            return response()->json(['message' => 'No se encontro la importacion'], 404);
        }

        $tipo = $request->query('tipo');

        $limit = (int) $request->query('limit', 50);
        if ($limit <= 0) {
            $limit = 50;
        }
        if ($limit > 200) {
            $limit = 200;
        }

        $offset = (int) $request->query('offset', 0);
        if ($offset < 0) {
            $offset = 0;
        }

        $query = ImportConflict::where('import_history_id', $import_history_id);

        if (!empty($tipo)) {
            $query->where('tipo', $tipo);
        }

        /* Total DEL FILTRO APLICADO (con el tipo, si vino) -- no del historial completo. */
        $total = (clone $query)->count();

        $conflicts = (clone $query)
                        ->orderBy('fila')
                        ->offset($offset)
                        ->limit($limit)
                        ->get();

        /*
         * Resumen por tipo y campo para el encabezado del modal: sobre el TOTAL del
         * historial, no sobre el filtro de $tipo -- si no, filtrar a un tipo dejaría
         * de mostrar el desglose por los demás tipos. Con agregados SQL, no iterando
         * la colección ya traída (que es justo el bug que este prompt arregla).
         */
        $resumen = ImportConflict::where('import_history_id', $import_history_id)
                        ->selectRaw('tipo, campo, COUNT(*) as total')
                        ->groupBy('tipo', 'campo')
                        ->get();

        return response()->json([
            'conflicts' => $conflicts,
            'resumen'   => $resumen,
            'total'     => $total,
            'limit'     => $limit,
            'offset'    => $offset,
        ]);
    }
}
