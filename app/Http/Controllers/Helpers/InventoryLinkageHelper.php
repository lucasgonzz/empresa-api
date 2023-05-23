<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\CommonLaravel\Helpers\UserHelper;
use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\Image;
use App\Models\InventoryLinkage;
use App\Models\PriceType;
use App\Models\Provider;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class InventoryLinkageHelper extends Controller {

	public $price_types;

	function __construct() {
		$this->setPriceTypes();
	}

	function setPriceTypes() {
		$this->price_types = PriceType::where('user_id', $this->userId())
											->orderBy('position', 'ASC')
											->get();
	}
	
	function setClientArticles($inventory_linkage) {
		$client = $inventory_linkage->client;
		$articles = Article::where('user_id', $this->userId())
							->orderBy('created_at', 'ASC')
							->get();

		$index = count($articles);
		foreach ($articles as $article) {
			$created_at = Carbon::now()->subSeconds($index);
			$this->createClientArticle($client, $article, $created_at);
			$index--;
		}
	}

	function checkArticle($article) {
		$inventory_linkages = InventoryLinkage::where('user_id', $this->userId())
												->get();
		if (count($inventory_linkages) >= 1) {
			foreach ($inventory_linkages as $inventory_linkage) {
				$client = $inventory_linkage->client;
				Log::info('Entro en la vinculacion del cliente '.$client->name);
				Log::info('Buscando article con user_id = '.$client->comercio_city_user_id.' y provider_article_id = '.$article->id);
				$client_article = Article::where('user_id', $client->comercio_city_user_id)
												->where('provider_article_id', $article->id)
												->first();
				if (!is_null($client_article)) {
					Log::info('Habia article');
					Log::info('Costo nuevo: '.$this->getClientArticlePrice($client, $article));
					$client_article->cost = $this->getClientArticlePrice($client, $article);
					$client_article->save();
					ArticleHelper::setFinalPrice($client_article, $client->comercio_city_user_id);
				} else {
					Log::info('No habia article');
					$client_article = $this->createClientArticle($client, $article);
				}
				$this->sendAddModelNotification('article', $client_article->id, false, $client->comercio_city_user_id);
			}
		}
	}

	function createClientArticle($client, $article, $created_at = null) {
		if (is_null($created_at)) {
			$created_at = Carbon::now();
		}
		$price = $this->getClientArticlePrice($client, $article);

		$provider_for_client = Provider::where('user_id', $client->comercio_city_user_id)
										->where('comercio_city_user_id', $this->userId())
										->first();

		$client_article = Article::create([
			'num'					=> $this->num('articles', $client->comercio_city_user_id),
			'bar_code'				=> $article->bar_code,
			'provider_code'			=> $article->provider_code,
			'name'					=> $article->name,
			'cost'					=> $price,
			'iva_id'				=> $article->iva_id,
			'provider_id'			=> $provider_for_client->id,
			'provider_article_id'	=> $article->id,
			'user_id'				=> $client->comercio_city_user_id,
			'created_at'			=> $created_at,
			'apply_provider_percentage_gain'	=> 1,
		]);
        foreach ($article->images as $image) {
            $client_article_image = Image::create([
                env('IMAGE_URL_PROP_NAME', 'image_url')     => $image->{env('IMAGE_URL_PROP_NAME', 'image_url')},
                'imageable_id'                              => $client_article->id,
                'imageable_type'                            => 'article',
            ]);
        }
		ArticleHelper::setFinalPrice($client_article, $client->comercio_city_user_id);
		return $client_article;
	}

	function getClientArticlePrice($client, $article) {
		if (count($this->price_types) >= 1) {
			$client_price_type = $this->price_types[count($this->price_types)-1];  
			if (!is_null($client->price_type)) {
				$client_price_type = $client->price_type;
			}
		} 

		$price = $article->final_price;
		// Log::info('final_price de '.$article->name.': '.$price);
		if (count($this->price_types) >= 1) {
			foreach ($this->price_types as $price_type) {
				if ($price_type->position <= $client_price_type->position) {
					$price = $price + ($price * $price_type->percentage / 100);
					// Log::info('Sumando el '.$price_type->percentage.' de '.$price_type->name.'. Quedo en: '.$price);
				}
			}
		}
		return $price;
	}

}