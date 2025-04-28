<?php

namespace App\Http\Controllers\Helpers\sale;

use App\Http\Controllers\Stock\StockMovementController;

class UpdateHelper {
	
	static function check_articulos_eliminados($sale, $items, $previus_articles, $se_esta_confirmando_por_primera_vez) {

		foreach ($previus_articles as $previus_article) {
			
			$se_elimino = true;

			foreach ($items as $item) {
				
				if (isset($item['is_article']) && $item['id'] == $previus_article->id) {
					$se_elimino = false;
				}

			}

			if ($se_elimino) {

				Self::save_stock_movement($sale, $previus_article);

			}

		}

	}

	static function save_stock_movement($sale, $article) {

        $ct = new StockMovementController();
        	
        $data = [];
        $data['model_id'] 			= $article->id;
        $data['from_address_id'] 	= null;

        if (count($article->addresses) >= 1) {

        	$data['to_address_id'] 		= $sale->address_id;
        }
        
        $data['amount'] 			= (float)$article->pivot->amount;
        $data['sale_id'] 			= $sale->id;
        $data['concepto_stock_movement_name'] 			= 'Se elimino de la venta';

        $ct->crear($data, false);
	}

}