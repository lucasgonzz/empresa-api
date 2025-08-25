<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\Helpers\ArticleHelper;
use Carbon\Carbon;

class PriceTypeHelper {
	
	static function check_recargos($price_type) {

		$hubo_cambios = false;

		foreach ($price_type->price_type_surchages as $price_type_surchage) {
			
			if ($price_type->updated_at <= Carbon::now()->subMinute()) {
				$hubo_cambios = true;
			}
		}

		if ($hubo_cambios) {
			ArticleHelper::setArticlesFinalPrice();
		}
	}



}