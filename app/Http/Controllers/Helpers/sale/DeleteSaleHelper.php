<?php

namespace App\Http\Controllers\Helpers\sale;

use App\Http\Controllers\Helpers\ArticleHelper;


class DeleteSaleHelper {


	static function regresar_stock($sale) {

        if (!$sale->to_check && !$sale->checked) {

            foreach ($sale->articles as $article) {
                if (!is_null($article->stock)) {
                    ArticleHelper::resetStock($article, $article->pivot->amount, $sale);
                }
            }

            foreach ($sale->combos as $combo) {
            	
            	foreach ($combo->articles as $article) {
            		
            		if (!is_null($article->stock)) {

            			$amount = $combo->pivot->amount * $article->pivot->amount;
                    	ArticleHelper::resetStock($article, $amount, $sale);
            		}
            	}
            }
        }
	}
}