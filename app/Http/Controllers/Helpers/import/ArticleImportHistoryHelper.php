<?php

namespace App\Http\Controllers\Helpers\import;

use App\Http\Controllers\CommonLaravel\Helpers\ImportHelper;
use App\Http\Controllers\Helpers\caja\MovimientoCajaHelper;
use App\Models\MovimientoCaja;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class ArticleImportHistoryHelper {

    static function attach_articulos_creados($import_history, $articulos_creados) {

        foreach ($articulos_creados as $article) {
            $import_history->articulos_creados()->attach($article->id);
        }
    }

    static function attach_articulos_actualizados($import_history, $articulos_actualizados, $updated_props) {

        foreach ($articulos_actualizados as $article) {
            $import_history->articulos_actualizados()->attach($article->id, [
                'updated_props'     => json_encode($updated_props[$article->id])
            ]);
        }
    }
	
}