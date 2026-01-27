<?php

namespace App\Http\Controllers\Helpers\sale;

use App\Models\ArticlePurchase;
use App\Models\SaleChannel;
use Illuminate\Support\Facades\Log;

class ArticlePurchaseHelper {
	
	function set_article_purcase($sale) {

		$this->user = $sale->user;

		$this->sale = $sale;

		if (!$this->sale->to_check && !$this->sale->checked) {
			
			$this->borrar_article_purchase_actuales($this->sale);

			foreach ($this->sale->articles as $article) {

				$this->article = $article;

				$this->article_purchase = ArticlePurchase::create([
					'client_id'		=> $this->sale->client_id,
					'sale_id'		=> $this->sale->id,
					'article_id'	=> $article->id,
					'category_id'	=> $article->category_id,
					// 'cost'			=> $article->pivot->cost,
					// 'price'			=> $article->pivot->price,
					'amount'		=> $article->pivot->amount,
					'created_at'	=> $this->sale->created_at,
					'sale_channel_id'	=> $this->get_sale_channel_id($this->sale),
					'address_id'	=> $this->sale->address_id,
				]);


				// $this->cotizar_costo();

				$this->set_costo_y_price();
					
			}

			$this->combos();
		}

	}

	function set_costo_y_price() {
		
		Log::info('set_costo_y_price, cost: '.$this->article->pivot->price);

		if ($this->sale->moneda_id == 1) {

			$this->article_purchase->cost = $this->article->pivot->cost;
			$this->article_purchase->price = $this->article->pivot->price;
			Log::info('se usa cost');

		} else if ($this->sale->moneda_id == 2) {

			$this->article_purchase->cost_dolar = $this->article->pivot->cost;
			$this->article_purchase->price_dolar = $this->article->pivot->price;
			Log::info('se usa cost_dolar');
		}
		
		$this->article_purchase->save();
	}

	function cotizar_costo() {

		$this->cost = $this->article->pivot->cost;
		Log::info('cost empieza en '.$this->cost);

		if ($this->sale->moneda_id == 1) {

			// Pesos

			if ($this->article->cost_in_dollars) {
				// Costo en dolares
				
				Log::info('cotizar_a_pesos');
				$this->cotizar_a_pesos(); 

			} else {
				// Costo queda igual
			}

		} else if ($this->sale->moneda_id == 2) {

			// Dolares


			if (!$this->article->cost_in_dollars) {
				// Costo en pesos

				Log::info('cotizar_a_dolares');
				$this->cotizar_a_dolares(); 

			} else {
				// Costo queda igual
			}
		}

		Log::info('cost termina en '.$this->cost);
	}

	function cotizar_a_pesos() {
		$this->cost = $this->article->pivot->cost * $this->user->dollar;
	}

	function cotizar_a_dolares() {
		$this->cost = $this->article->pivot->cost / $this->user->dollar;
	}

	function get_sale_channel_id() {
		if ($this->sale->meli_order_id) {
			return SaleChannel::where('slug', 'mercado_libre')->first()->id;
		}
		if ($this->sale->order_id) {
			return SaleChannel::where('slug', 'ecommerce')->first()->id;
		}
		if ($this->sale->tienda_nube_order_id) {
			return SaleChannel::where('slug', 'tienda_nube')->first()->id;
		}
		return SaleChannel::where('slug', 'sistema')->first()->id;
	}

	function combos() {
		foreach ($this->sale->combos as $combo) {

			foreach ($combo->articles as $article) {
				ArticlePurchase::create([
					'client_id'		=> $this->sale->client_id,
					'sale_id'		=> $this->sale->id,
					'article_id'	=> $article->id,
					'category_id'	=> $article->category_id,
					'cost'			=> $article->cost,
					'price'			=> $article->price,
					'amount'		=> $combo->pivot->amount * $article->pivot->amount,
					'created_at'	=> $this->sale->created_at,
				]);
			}
		}
	}

	function borrar_article_purchase_actuales($sale) {

		ArticlePurchase::where('sale_id', $sale->id)
						->delete();
	}

}