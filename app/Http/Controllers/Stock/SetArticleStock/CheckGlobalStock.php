<?php

namespace App\Http\Controllers\Stock\SetArticleStock;
use Illuminate\Support\Facades\Log;

class CheckGlobalStock {
    

    static function check_global_stock($stock_movement, $article, $set_updated_at) {

        if (is_null($article->stock)) {
            $article->stock = 0;
            $article->save();
        }

        $article->load('addresses');

        if (
            !is_null($article->stock) 
            && count($article->addresses) == 0
            && count($article->article_variants) == 0
        ) {

            Log::info('Se va a sumar global stock');

            /*
                Se aumenta el stock del articulo con la amount del stock_movement
                Ya que, si es una venta, amount va a ser negativo
            */
                
            $article->stock += (float)$stock_movement->amount;

            if (!$set_updated_at) {
                $article->timestamps = false;
            }
            
            $article->save();

            // if (!isset($request->from_excel_import) || !$request->from_excel_import) {
            //     $ct = new InventoryLinkageHelper(null, $user_id);
            //     $ct->check_is_agotado($article);
            // }

        }
    }


}