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
                                        // ->where('user_id', $this->userId())
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

        RollbackArticleImportHistory::dispatch($import_history->id, $import_history->user_id);

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
        });

        return response()->json(['models' => $models], 200);
    }

    function chunks($import_history_id) {
        $models = ArticleImportResult::where('import_history_id', $import_history_id)
                                    ->with('article_import_result_observations')
                                    ->get();

        return response()->json(['models' => $models], 200);
    }

    function updated_models($import_result_id) {
        $model = ArticleImportResult::where('id', $import_result_id)
                            ->with('articulos_actualizados')
                            ->first();
        return response()->json(['model' => $model], 200);
    }

    function created_models($import_result_id) {
        $model = ArticleImportResult::where('id', $import_result_id)
                            ->with('articulos_creados')
                            ->first();
                            
        return response()->json(['model' => $model], 200);
    }

    /**
     * Devuelve la lista de artículos creados con código repetido para un ImportHistory dado.
     * Recorre todos los chunks del historial y recolecta los IDs almacenados en cada
     * ArticleImportResult.created_with_repeated_code_ids, luego trae los artículos de la BD.
     *
     * @param int $import_history_id ID del historial de importación.
     * @return \Illuminate\Http\JsonResponse Array de artículos con id, name, bar_code, provider_code.
     */
    function repeated_code_articles($import_history_id) {
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
            return response()->json([], 200);
        }

        /* Traer los artículos con los datos mínimos para mostrar en la lista. */
        $articles = Article::whereIn('id', array_unique($all_ids))
            ->select('id', 'name', 'bar_code', 'provider_code')
            ->orderBy('id', 'ASC')
            ->get();

        return response()->json($articles, 200);
    }

    /**
     * Devuelve los conflictos de una importacion (identificadores ambiguos, placeholders
     * descartados y filas sin identificador), paginados y agrupados por tipo/campo para
     * el encabezado del modal. Filtra por user_id para que un usuario no pueda leer los
     * conflictos de otro (prompt 02, grupo 229; UI en prompt 05).
     *
     * @param int $import_history_id ID del historial de importacion.
     * @return \Illuminate\Http\JsonResponse
     */
    function conflicts($import_history_id) {

        /* Se filtra por el usuario autenticado para no exponer conflictos de otro owner/empleado. */
        $import_history = ImportHistory::where('id', $import_history_id)
                            ->where('user_id', $this->userId())
                            ->first();

        if (is_null($import_history)) {
            return response()->json(['message' => 'No se encontro la importacion'], 404);
        }

        $conflicts = ImportConflict::where('import_history_id', $import_history_id)
                        ->orderBy('fila')
                        ->get();

        /* Resumen por tipo y campo, para el encabezado del modal. */
        $resumen = [];

        foreach ($conflicts as $c) {
            $clave = $c->tipo . '|' . (string) $c->campo;
            if (!isset($resumen[$clave])) {
                $resumen[$clave] = [
                    'tipo'   => $c->tipo,
                    'campo'  => $c->campo,
                    'total'  => 0,
                ];
            }
            $resumen[$clave]['total']++;
        }

        return response()->json([
            'conflicts' => $conflicts,
            'resumen'   => array_values($resumen),
            'total'     => $conflicts->count(),
        ]);
    }
}
