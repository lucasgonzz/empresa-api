<?php

namespace App\Http\Controllers\Helpers\sale;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Models\ConceptoStockMovement;
use App\Models\StockMovement;


class DeleteSaleHelper {


	static function regresar_stock($sale) {

        if (!$sale->to_check && !$sale->checked) {

            foreach ($sale->articles as $article) {
                if (!is_null($article->stock)) {

                    $amount = $article->pivot->amount;
                    $amount -= self::get_unidades_ya_devueltas_en_nota_de_credito($sale, $article);
                    
                    ArticleHelper::resetStock($article, $amount, $sale);
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

    static function get_unidades_ya_devueltas_en_nota_de_credito($sale, $article) {

        $unidades_ya_devueltas = 0;

        $concepto = ConceptoStockMovement::where('name', 'Nota de credito')->first();

        $stock_movement_nota_credito = StockMovement::where('article_id', $article->id)
                                                    ->where('concepto_stock_movement_id', $concepto->id)
                                                    ->where('sale_id', $sale->id)
                                                    ->get();

        foreach ($stock_movement_nota_credito as $stock_movement) {
            
            $unidades_ya_devueltas += $stock_movement->amount;
        }

        return $unidades_ya_devueltas;
    }
}