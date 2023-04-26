<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\Helpers\SaleHelper;
use App\Models\Sale;

class SaleChartHelper {
	
	static function getCharts($instace, $from, $until) {
		$cantidad_ventas = 0;
		$total_ventas = 0;
		$categorias = [];
		$sub_categorias = [];
		$articulos = [];

		$sales = Sale::where('user_id', $instace->userId())
						->where('created_at', '>=', $from)
						->where('created_at', '<=', $until)
						->get();
		foreach ($sales as $sale) {
			$cantidad_ventas++;
			$total_ventas += SaleHelper::getTotalSale($sale);
			foreach ($sale->articles as $article) {
				if (isset($articulos[$article->id])) {
					$articulos[$article->id]['amount'] += (float)$article->pivot->amount;
				} else {
					$articulos[$article->id] = [
						'name'		=> $article->name,
						'amount'	=> (float)$article->pivot->amount, 
					]; 
				}

				if (!is_null($article->category_id)) {
					if (isset($categorias[$article->category_id])) {
						$categorias[$article->category_id]['amount'] += (float)$article->pivot->amount;
					} else {
						$categorias[$article->category_id] = [
							'name'		=> $article->category->name,
							'amount'	=> (float)$article->pivot->amount, 
						]; 
					}
				}

				if (!is_null($article->sub_category_id)) {
					if (isset($sub_categorias[$article->sub_category_id])) {
						$sub_categorias[$article->sub_category_id]['amount'] += (float)$article->pivot->amount;
					} else {
						$sub_categorias[$article->sub_category_id] = [
							'name'		=> $article->sub_category->name.', categoria: '.$article->sub_category->category->name,
							'amount'	=> (float)$article->pivot->amount, 
						]; 
					}
				} 
			}
		}

		usort($articulos, function($a, $b) { 
			return $b['amount'] - $a['amount']; 
		});

		usort($categorias, function($a, $b) { 
			return $b['amount'] - $a['amount']; 
		});

		usort($sub_categorias, function($a, $b) { 
			return $b['amount'] - $a['amount']; 
		});

		return [
			'cantidad_ventas' 	=> $cantidad_ventas,
			'total_ventas' 		=> $total_ventas,
			'articulos' 		=> $articulos,
			'categorias' 		=> $categorias,
			'sub_categorias' 	=> $sub_categorias,
		];

	}

}