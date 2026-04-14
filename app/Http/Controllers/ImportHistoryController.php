<?php

namespace App\Http\Controllers;

use App\Jobs\RollbackArticleImportHistory;
use App\Models\ArticleImportResult;
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
}
