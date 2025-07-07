<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\CommonLaravel\Helpers\RequestHelper;
use App\Http\Controllers\Stock\StockMovementController;

class PromocionVinotecaHelper {
	

	static function set_cost($promo) {
		$total_cost = 0;

		foreach ($promo->articles as $article) {
			$total_article = $article->pivot->amount * $article->cost;

			if (!is_null($article->presentacion)) {
				$total_article *= $article->presentacion;
			}

			$total_cost += $total_article;
		}

		if (
			$total_cost > 0
			&& $promo->stock > 0
		) {
			$total_cost = $total_cost / $promo->stock; 
		}

		$promo->cost = $total_cost;
		$promo->save();
	}

	static function attach_articles($promo, $articles) {

		foreach ($articles as $article) {

			$amount = RequestHelper::isset_array($article['pivot'], 'amount');

			$promo->articles()->attach($article['id'], [
				'amount'				=> $amount,
				'unidades_por_promo'	=> RequestHelper::isset_array($article['pivot'], 'unidades_por_promo'),
			]);

			if (!is_null($amount)) {
				Self::descontar_stock($promo, $article);
			}

		}
	}

	static function descontar_stock($promo, $article) {

		$data = [
			'model_id'						=> $article['id'],
			'amount'						=> -$article['pivot']['amount'],
			'concepto_stock_movement_name'	=> 'Creacion de Promocion'
		];

		if ($promo->address_id) {
			$data['from_address_id'] = $promo->address_id;
		}

		$ct = new StockMovementController();
		$ct->crear($data);
	}



	static function regresar_stock($promo, $articles) {

		foreach ($articles as $article) {
			
			if ($article['amount_para_regrersar']) {

				Self::regrersar_article_stock($promo, $article, $article['amount_para_regrersar']);
			}
		}
	}

	static function regrersar_article_stock($promo, $article, $amount_para_regrersar) {

		$data = [
			'model_id'						=> $article['id'],
			'amount'						=> $amount_para_regrersar,
			'concepto_stock_movement_name'	=> 'Eliminacion de Promocion'
		];

		if ($promo->address_id) {
			$data['from_address_id'] = $promo->address_id;
		}

		$ct = new StockMovementController();
		$ct->crear($data);
	}

}