<?php

namespace App\Http\Controllers\Helpers;

use App\Models\Article;
use App\Models\PriceType;
use Carbon\Carbon;

class ExportHelper {

	static function getPriceTypes() {
		return PriceType::where('user_id', UserHelper::userId())
								->whereNotNull('position')
								->orderBy('position', 'ASC')
								->get();
	}
	
	static function mapPriceTypes($map, $article) {
		$price_types = Self::getPriceTypes();
		if (count($price_types) >= 1) {
			foreach ($price_types as $price_type) {
				$map[] = $article->{$price_type->name};
			}
		}
		return $map;
	}
	
	static function setPriceTypesHeadings($headings) {
		$price_types = Self::getPriceTypes();
		if (count($price_types) >= 1) {
			foreach ($price_types as $price_type) {
				$headings[] = $price_type->name;
			}
		}
		return $headings;
	}
	
	static function setPriceTypes($articles) {
		$price_types = Self::getPriceTypes();
		if (count($price_types) >= 1) {
			foreach ($articles as $article) {
				$price = $article->final_price;
				foreach ($price_types as $price_type) {
					$price = $price + ($price * $price_type->percentage / 100);
					$article->{$price_type->name} = $price; 
				}
			}
		}
		return $articles;
	}

}