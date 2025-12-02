<?php

namespace App\Http\Controllers\Helpers\sale;

use App\Models\ArticlePurchase;
use App\Models\SaleChannel;

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
					'sale_channel_id'	=> Self::get_sale_channel_id($sale),
					'address_id'	=> $sale->address_id,
				]);
					
			}

			Self::combos($sale);
		}

	}

	static function get_sale_channel_id($sale) {
		if ($sale->meli_order_id) {
			return SaleChannel::where('slug', 'mercado_libre')->first()->id;
		}
		if ($sale->order_id) {
			return SaleChannel::where('slug', 'ecommerce')->first()->id;
		}
		if ($sale->tienda_nube_order_id) {
			return SaleChannel::where('slug', 'tienda_nube')->first()->id;
		}
		return SaleChannel::where('slug', 'sistema')->first()->id;
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