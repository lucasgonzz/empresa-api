<?php

namespace App\Http\Controllers\Helpers\sale;

use App\Http\Controllers\Stock\StockMovementController;
use App\Models\ArticlePurchase;

class ComboHelper {
	
	static function discount_articles_stock($sale, $combo) {

		if (!$sale->to_check && !$sale->checked) {
			
			foreach ($combo['articles'] as $article) {

				Self::crear_stock_movement($sale, $combo, $article);
			}
		}

	}

	static function crear_stock_movement($sale, $combo, $article) {

		$ct = new StockMovementController();

		$data['model_id'] 			= $article['id'];
		$data['from_address_id'] 	= $sale->address_id;
		$data['amount'] 			= -((float)$article['pivot']['amount'] * (int)$combo['amount']);
		$data['sale_id'] 			= $sale->id;
		$data['concepto_stock_movement_name'] 			= 'Venta';
		$data['observations'] 		= $combo['amount'].' combo '.$combo['name'];

		$ct->crear($data, false);
	}

}