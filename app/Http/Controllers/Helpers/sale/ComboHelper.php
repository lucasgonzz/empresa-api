<?php

namespace App\Http\Controllers\Helpers\sale;

use App\Http\Controllers\Stock\StockMovementController;

class ComboHelper {
	
	static function discount_articles_stock($sale, $combo, $previus_combos) {

		if (!$sale->to_check && !$sale->checked) {

			$combo_amount = Self::get_combo_amount($combo, $previus_combos);
			
			foreach ($combo['articles'] as $article) {

				Self::crear_stock_movement($sale, $combo, $article, $combo_amount);
			}
		}

	}

	static function crear_stock_movement($sale, $combo, $article, $combo_amount) {

		$ct = new StockMovementController();

		$data['model_id'] 			= $article['id'];
		$data['from_address_id'] 	= $sale->address_id;
		$data['amount'] 			= -((float)$article['pivot']['amount'] * $combo_amount);
		$data['sale_id'] 			= $sale->id;
		$data['concepto_stock_movement_name'] 			= 'Venta';
		$data['observations'] 		= $combo_amount.' combo '.$combo['name'];

		$ct->crear($data, false);
	}

	static function get_combo_amount($combo, $previus_combos) {

		$finded_combo = null;
		$amount = (int)$combo['amount'];

		if (!is_null($previus_combos)) {
			
			foreach ($previus_combos as $previus_combo) {
				
				if ($previus_combo->id == $combo['id']) {

					$finded_combo = $previus_combo;
				}
			}

			if (!is_null($previus_combo)) {

				$amount -= (int)$previus_combo->pivot->amount;
			}
		}

		return $amount;
	}

}