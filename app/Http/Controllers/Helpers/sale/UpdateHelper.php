<?php

namespace App\Http\Controllers\Helpers\sale;

use App\Http\Controllers\StockMovementController;

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
        $request = new \Illuminate\Http\Request();
        
        $request->model_id = $article->id;
        $request->from_address_id = null;
        $request->to_address_id = $sale->address_id;
        $request->amount = (float)$article->pivot->amount;
        $request->sale_id = $sale->id;
        $request->concepto = 'Se elimino de la venta '.$sale->num;

        $ct->store($request, false);
	}

}