<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\CommonLaravel\Helpers\UserHelper;
use App\Models\Article;
use App\Models\PriceType;
use App\Models\Sale;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

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
	
	static function mapCharts($map, $article) {
		// Log::info($map);
		// Log::info('Ventas en los ultimos 3 meses');
		// Log::info($article->{'Ventas en los ultimos 3 meses'});
		// Log::info($article->{'Ventas en el ultimo mes'});
		// $map[] = $article->{'Ventas en los ultimos 3 meses'};
		$map[] = $article->{'Ventas en el ultimo mes'};
		// Log::info($map);
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
	
	static function setChartsheadings($headings) {
		// $headings[] = 'Ventas en los ultimos 3 meses';
		$headings[] = 'Ventas en el ultimo mes';
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
	
	static function setCharts($articles) {
		foreach ($articles as $article) {
			$from_date = Carbon::today()->subMonth()->startOfMonth();
			$until_date = Carbon::today()->subMonth()->endOfMonth();
			$article_id = $article->id;

			$sales_ultimo_mes = Self::getSales($from_date, $until_date, $article_id);

			// $from_date = Carbon::today()->subMonths(3)->startOfMonth();
			// $sales_ultimos_tres_meses = Self::getSales($from_date, $until_date, $article_id);

	        $article->{'Ventas en el ultimo mes'} = count($sales_ultimo_mes);
	        // $article->{'Ventas en los ultimos 3 meses'} = 1;
	        // $article->{'Ventas en los ultimos 3 meses'} = count($sales_ultimos_tres_meses);
		}
		return $articles;
	}

	static function getSales($from_date, $until_date, $article_id) {
		$user_id = UserHelper::userId();
		$sales = Sale::where('user_id', $user_id)
            			->whereDate('created_at', '>=', $from_date)
                        ->whereDate('created_at', '<=', $until_date)
                        ->whereHas('articles', function(Builder $query) use ($article_id) {
                            $query->where('article_id', $article_id);
                        })
                        ->pluck('id');
        return $sales;
	}

}