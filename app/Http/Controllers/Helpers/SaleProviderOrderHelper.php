<?php

namespace App\Http\Controllers\Helpers;

use App\Models\Article;
use App\Http\Controllers\Helpers\ProviderOrderHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Models\Provider;
use App\Models\ProviderOrder;
use Carbon\Carbon;

class SaleProviderOrderHelper {

	static function createProviderOrder($sale, $instance) {
		if (!is_null($sale->client) && !is_null($sale->client->comercio_city_user)) {
			$client_comercio_city = $sale->client->comercio_city_user;
			$provider = Provider::where('user_id', $client_comercio_city->id)
								->where('comercio_city_user_id', $instance->userId())
								->first();
			if (!is_null($provider)) {
		        $provider_order = ProviderOrder::create([
		            'num'         => $instance->num('provider_orders', $client_comercio_city->id),
		            'provider_id' => $provider->id,
		            'user_id'     => $client_comercio_city->id,
		            'provider_order_status_id'	=> 1,
		        ]);
		        Self::attachArticles($sale, $provider_order, $client_comercio_city);
		        $instance->sendAddModelNotification('provider_order', $provider_order->id, false, $client_comercio_city->id);
			}
		}
	}

	static function attachArticles($sale, $provider_order, $client_comercio_city) {
		foreach ($sale->articles as $article) {
			$article_from_client = Self::getArticleFromClient($client_comercio_city, $article);
			if (is_null($article_from_client)) {
				$article_from_client = Article::create([
					'user_id' => $client_comercio_city->id,
					'status'  => 'inactive',
					'name'	  => $article->name,
				]);						
			} 
			$provider_order->articles()->attach($article_from_client->id, [
											'amount' => $article->pivot->amount,
											'cost'   => Self::getArticlePrice($article),
										]);
		}
	}

	static function getArticlePrice($article) {
		$price = $article->pivot->price;
		if (!is_null($article->pivot->bonus)) {
			$price = $price - ($price * $article->pivot->bonus / 100);
		}
		return $price;
	}

	static function getArticleFromClient($client_comercio_city, $article) {
		if ($article->bar_code != '') {
			$_article = Article::where('bar_code', $article->bar_code);
		} else if ($article->provider_code != '') {
			$_article = Article::where('provider_code', $article->provider_code);
		} else {
			$_article = Article::where('name', $article->name);
		}
		$_article = $_article->where('user_id', $client_comercio_city->id)
							->first();
		return $_article;
	}
	
}