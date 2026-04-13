<?php

namespace App\Http\Controllers;

use App\Models\ArticleImportResult;
use App\Models\ImportHistory;
use Illuminate\Http\Request;

class ImportHistoryController extends Controller
{
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
