<?php

namespace App\Http\Controllers\Stock;

use App\Models\ConceptoStockMovement;
use Illuminate\Support\Facades\Log;

class SetProvider  {

    static function set_provider($stock_movement, $article, $data) {

        if (
            !is_null($article) 
            && !is_null($stock_movement->provider)
            && !isset($data['not_save_provider'])
        ) {
            $article->provider_id = $stock_movement->provider_id;
            $article->save();
        }

    }

 }