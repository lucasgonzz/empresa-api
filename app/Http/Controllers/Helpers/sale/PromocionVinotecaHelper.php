<?php

namespace App\Http\Controllers\Helpers\sale;

use App\Http\Controllers\Stock\StockMovementController;
use App\Models\PromocionVinoteca;

class PromocionVinotecaHelper {
	
	static function discount_stock_promocion_vinoteca($sale, $promo, $previus_promos = null) {

		if (!$sale->to_check && !$sale->checked) {

			$promo_amount = Self::get_promo_amount($promo, $previus_promos);
			
			$promo_model = PromocionVinoteca::find($promo['id']);
			$promo_model->stock -= $promo_amount;
			$promo_model->save();
		}

	}

	static function get_promo_amount($promo, $previus_promos) {

		$finded_promo = null;
		$amount = (int)$promo['amount'];

		if (!is_null($previus_promos)) {
			
			foreach ($previus_promos as $previus_promo) {
				
				if ($previus_promo->id == $promo['id']) {

					$finded_promo = $previus_promo;
				}
			}

			if (!is_null($finded_promo)) {

				$amount -= (int)$finded_promo->pivot->amount;
			}
		}

		return $amount;
	}

}