<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\Helpers\CajaChartsHelper;
use App\Models\Article;
use App\Models\PriceType;
use App\Models\Sale;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

class ArticleSalesExportHelper {
	
	static function mapCharts($map, $article) {
		$map[] = $article->amount;
		// $map[] = $article->{'Ventas en los ultimos 3 meses'};
		// Log::info($map);
		return $map;
	}
	
	static function setChartsheadings($headings) {
		$headings[] = 'Ventas';
		// $headings[] = 'Ventas en los ultimos 3 meses';
		return $headings;
	}
	
	static function setCharts($user) {
		$from_date = Carbon::today()->subMonth()->startOfMonth();
		$until_date = Carbon::today()->subMonth()->endOfMonth();
		$chart = CajaChartsHelper::charts(null, $from_date, $until_date, $user->id, false);
		$articles = [];
		foreach ($chart['article'] as $_article) {
			$article = new \stdClass;
			$article->num 			= $_article['num'];
			$article->bar_code 		= $_article['bar_code'];
			$article->provider_code = $_article['provider_code'];
			$article->provider 		= $_article['provider'];
			$article->name 			= $_article['name'];
			$article->amount 		= $_article['amount'];
			// $article->rentabilidad 	= $_article['rentabilidad'];
			
			$articles[] 			= $article;
		}
		$articles = collect($articles);
		return $articles;


		foreach ($articles as $article) {
			$from_date = Carbon::today()->subMonth()->startOfMonth();
			$until_date = Carbon::today()->subMonth()->endOfMonth();
			$article_id = $article->id;

			$chart = CajaChartsHelper::charts(null, $from_date, $until_date, $user->id);
			$sales_ultimo_mes = $chart['article'];

			// $sales_ultimo_mes = Self::getUnidadesVendidas($article, $user, $from_date, $until_date, $article_id);

			// $from_date = Carbon::today()->subMonths(3)->startOfMonth();
			// $until_date = Carbon::today()->subMonth(1)->endOfMonth();
			// $sales_ultimos_tres_meses = Self::getSales($user, $from_date, $until_date, $article_id);

	        $article->{'Ventas'} = $sales_ultimo_mes;
	        // $article->{'Ventas en los ultimos 3 meses'} = count($sales_ultimos_tres_meses) + count($sales_ultimo_mes);
		}
		// usort($articles, function($a, $b) { 
		// 	return $b->Ventas - $a->Ventas; 
		// });
		return $articles;
	}


	static function getUnidadesVendidas($article, $user, $from_date, $until_date, $article_id) {
		$sales = Sale::where('user_id', $user->id)
            			->whereDate('created_at', '>=', $from_date)
                        ->whereDate('created_at', '<=', $until_date)
                        ->whereHas('articles', function(Builder $query) use ($article_id) {
                            $query->where('article_id', $article_id);
                        })
                        ->get();
        $unidades_vendidas = 0;
        foreach ($sales as $sale) {
        	foreach ($sale->articles as $article_sale) {
        		if ($article_sale->id == $article->id) {
        			$unidades_vendidas += $article_sale->pivot->amount;
        		}
        	}
        }
        return $unidades_vendidas;
	}

}