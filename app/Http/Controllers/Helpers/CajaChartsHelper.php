<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\Helpers\SaleHelper;
use App\Models\Sale;

class CajaChartsHelper {
	
	static function charts($instance, $from_date, $until_date) {
		$cantidad_ventas = 0;
		$total_ventas = 0;
		$categorias = [];
		$sub_categorias = [];
		$articulos = [];

		$sales = Sale::where('user_id', $instance->userId())
                        ->orderBy('created_at', 'ASC');
        if (!is_null($until_date)) {
            $sales = $sales->whereDate('created_at', '>=', $from_date)
                            ->whereDate('created_at', '<=', $until_date);
        } else {
            $sales = $sales->whereDate('created_at', $from_date);
        }
		$sales = $sales->get();

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
							'name'		=> $article->sub_category->name.' ('.$article->sub_category->category->name.')',
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
			'article' 			=> $articulos,
			'category' 		=> $categorias,
			'sub_category' 	=> $sub_categorias,
		];

	}

}