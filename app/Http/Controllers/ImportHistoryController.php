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
        RollbackArticleImportHistory::dispatch($import_history->id, $this->userId(), $this->userId(false));

        return response()->json([
            'queued' => true,
            'message' => 'Rollback encolado correctamente',
        ], 202);
    }

    /**
     * Historial de importaciones de un modelo, paginado y sin relaciones.
     *
     * Antes traía las últimas 10 CON `chunks.article_import_result_observations` sin
     * límite: en producción de Servian eso generó consultas de hasta 118.000 filas /
     * 113 MB (misión importacion-lento-vender-y-historial, 28/9/2026) -- un dueño con
     * muchas importaciones grandes trae, de una sola vez, todos los lotes y todas las
     * observaciones de cada una, cuando la tabla del historial solo pinta columnas
     * propias de `ImportHistory` (relevado del `.vue` real, no a ojo). Los lotes de cada
     * importación ("Lotes") ya son un endpoint aparte (`chunks()`, más abajo) que el
     * frontend pide bajo demanda al abrir ese modal puntual.
     *
     * `select()` explícito + `paginate(5)` en vez de `take(10)`: el `select` sobrevive al
     * `paginate()` (Eloquent solo usa el `['*']` del segundo argumento si el builder
     * todavía no tiene columnas seteadas -- mismo mecanismo que ya documenta
     * `SearchController::globalSearch()` en este mismo repo).
     *
     * @param string                    $model_name
     * @param \Illuminate\Http\Request  $request     query: page (default 1).
     * @return \Illuminate\Http\JsonResponse
     */
    function index($model_name, Request $request) {
        $pagina_pedida = (int) $request->query('page', 1);
        if ($pagina_pedida < 1) {
            $pagina_pedida = 1;
        }

        $models = ImportHistory::select([
                                    'id', 'created_at', 'terminado_at', 'status', 'user_id',
                                    'employee_id', 'filas_procesadas', 'created_models',
                                    'updated_models', 'articles_match', 'articles_repetidos',
                                    'error_message', 'error_trace', 'provider_id', 'columnas',
                                    'operaciones', 'observations', 'total_chunks',
                                    'processed_chunks', 'conflicts_count', 'excel_url',
                                    'rollback_status', 'rolled_back_at', 'rollback_error',
                                    'matching_counts_json', 'model_name',
                                ])
                                ->where('user_id', $this->userId())
                                ->where('model_name', $model_name)
                                ->orderBy('id', 'DESC')
                                ->paginate(5, ['*'], 'page', $pagina_pedida);

        /*
         * matching_counts_json se guarda como texto plano (json_encode) en BD, igual que
         * el resto de columnas JSON del repo (ver ArticleImportHelper). Se decodifica acá
         * para que el frontend reciba un array ya parseado en vez de un string JSON
         * anidado. filas_ambiguas e identificadores_descartados ya viajan tal cual porque
         * son columnas enteras (grupo 232, prompt 03; la UI que los consuma es otro prompt).
         */
        $models->getCollection()->each(function ($model) {
            $model->matching_counts_json = is_null($model->matching_counts_json)
                ? null
                : json_decode($model->matching_counts_json, true);

            /* Mismo criterio y mismo lugar que MasiveUpdateController::index() (grupo 305, prompt 02). */
            $model->can_revert = $model->puede_revertirse();
        });

        return response()->json([
            'models'     => $models->items(),
            'pagination' => [
                'current_page' => $models->currentPage(),
                'last_page'    => $models->lastPage(),
                'total'        => $models->total(),
                'per_page'     => $models->perPage(),
            ],
        ], 200);
    }

    /**
     * Devuelve los chunks (ArticleImportResult) de un historial de importacion, paginados.
     * Verifica que el historial pertenezca al usuario autenticado antes de
     * listar sus chunks, para no exponer datos de importaciones ajenas
     * (grupo 240, prompt 01).
     *
     * Antes traia TODOS los chunks del historial de una sola vez, con
     * `with('article_import_result_observations')` sin ningun limite -- igual que index()
     * antes de paginarse (misma mision), pero aca el caso extremo pesa mas: cada fila
     * procesada del Excel deja exactamente una observacion (ProcessArticleChunk::handle()
     * / ArticleImport::model()), asi que una importacion grande de una sola tanda puede
     * dejar cientos de miles. Medido con 587 chunks x 200 observaciones (~117.400 filas,
     * el mismo volumen del slow log real de Servian): agota el memory_limit de 128MB de
     * un worker default y, con 2GB, tarda 4,17s y pesa 41,26MB (mision de seguimiento,
     * 28/9/2026). Ahora pagina de a 20 (page por query string), con `with()` acotado a
     * los chunks de la pagina actual -- no a todos.
     *
     * `orderBy('chunk_number')` es necesario para que la paginacion sea estable: sin un
     * orden explicito, MySQL no garantiza que dos paginas consecutivas no se solapen ni
     * salteen filas.
     *
     * El boton "Filas" de chunks/Index.vue sigue leyendo
     * `chunk.article_import_result_observations` ya cargado en memoria (no pide aparte),
     * asi que sigue andando para cualquier chunk que la pagina actual traiga.
     *
     * @param int                       $import_history_id ID del historial de importacion.
     * @param \Illuminate\Http\Request  $request            query: page (default 1).
     * @return \Illuminate\Http\JsonResponse
     */
    function chunks($import_history_id, Request $request) {
        /* Confirmamos que el historial exista y sea del usuario autenticado. */
        $import_history = ImportHistory::where('id', $import_history_id)
                            ->where('user_id', $this->userId())
                            ->first();

        if (is_null($import_history)) {
            return response()->json(['message' => 'No se encontro la importacion'], 404);
        }

        $pagina_pedida = (int) $request->query('page', 1);
        if ($pagina_pedida < 1) {
            $pagina_pedida = 1;
        }

        $models = ArticleImportResult::where('import_history_id', $import_history_id)
                                    ->orderBy('chunk_number', 'ASC')
                                    ->with('article_import_result_observations')
                                    ->paginate(20, ['*'], 'page', $pagina_pedida);

        return response()->json([
            'models'     => $models->items(),
            'pagination' => [
                'current_page' => $models->currentPage(),
                'last_page'    => $models->lastPage(),
                'total'        => $models->total(),
                'per_page'     => $models->perPage(),
            ],
        ], 200);
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
