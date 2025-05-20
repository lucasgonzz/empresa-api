<?php

namespace App\Http\Controllers\Helpers\sale;

class SaleTotalesHelper {
	
	static function set_total_cost($sale) {

		$total = null;

		if (!$sale->to_check && !$sale->checked) {
			
			$total = 0;

			foreach ($sale->articles as $article) {

				if (!is_null($article->pivot->cost)) {

					$total += $article->pivot->cost * $article->pivot->amount;		
				}
			}

			foreach ($sale->promocion_vinotecas as $promo) {

				if (!is_null($promo->cost)) {

					$total += $promo->cost * $promo->pivot->amount;		
				}
			}
		}

		$sale->total_cost = $total;
		$sale->timestamps = false;
		$sale->save();
		return $sale;
	}

}