<?php

namespace App\Http\Controllers\Helpers\sale;

use App\Models\ArticlePurchase;

class ArticlePurchaseHelper {
	
	static function set_article_purcase($sale) {

		if (!$sale->to_check && !$sale->checked) {
			
			Self::borrar_article_purchase_actuales($sale);

			foreach ($sale->articles as $article) {

				ArticlePurchase::create([
					'client_id'		=> $sale->client_id,
					'sale_id'		=> $sale->id,
					'article_id'	=> $article->id,
					'category_id'	=> $article->category_id,
					'cost'			=> $article->pivot->cost,
					'price'			=> $article->pivot->price,
					'amount'		=> $article->pivot->amount,
					'created_at'	=> $sale->created_at,
				]);
					
			}

			Self::combos($sale);
		}

	}

	static function combos($sale) {
		foreach ($sale->combos as $combo) {

			foreach ($combo->articles as $article) {
				ArticlePurchase::create([
					'client_id'		=> $sale->client_id,
					'sale_id'		=> $sale->id,
					'article_id'	=> $article->id,
					'category_id'	=> $article->category_id,
					'cost'			=> $article->cost,
					'price'			=> $article->price,
					'amount'		=> $combo->pivot->amount * $article->pivot->amount,
					'created_at'	=> $sale->created_at,
				]);
			}
		}
	}

	static function borrar_article_purchase_actuales($sale) {

		ArticlePurchase::where('sale_id', $sale->id)
						->delete();
	}

}