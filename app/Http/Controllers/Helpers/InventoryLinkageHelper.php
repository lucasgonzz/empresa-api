<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\CommonLaravel\Helpers\UserHelper;
use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\Category;
use App\Models\Image;
use App\Models\InventoryLinkage;
use App\Models\PriceType;
use App\Models\Provider;
use App\Models\SubCategory;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\Builder;

class InventoryLinkageHelper extends Controller {

	public $price_types;
	public $user_id;

	function __construct($inventory_linkage = null, $user_id = null) {
        set_time_limit(999999);
		$this->user_id = $user_id;
		if (is_null($user_id)) {
			$this->user_id = $this->userId();
		} 
		if (!is_null($inventory_linkage)) {
			$this->inventory_linkage = $inventory_linkage;
			$this->client = $inventory_linkage->client;
			$this->setProviderForClient();
		}
		$this->setPriceTypes();
	}

	function setPriceTypes() {
		$this->price_types = PriceType::where('user_id', $this->user_id)
											->orderBy('position', 'ASC')
											->get();
	}

	function setProviderForClient() {
		$this->provider_for_client = Provider::where('user_id', $this->inventory_linkage->client->comercio_city_user_id)
												->where('comercio_city_user_id', $this->user_id)
												->first();
	}

	function setClientCategories() {
		$categories = Category::where('user_id', $this->userId())
								->get();

		$index = count($categories);
		foreach ($categories as $category) {
			Category::create([
				'num'					=> $this->num('categories', $this->inventory_linkage->client->comercio_city_user_id),
				'name'					=> $category->name,
				'image_url'				=> $category->image_url,
				'user_id'				=> $this->inventory_linkage->client->comercio_city_user_id,
				'provider_category_id'	=> $category->id,
				'created_at'			=> Carbon::now()->subSeconds($index),
			]);
			$index--;
		}
	}

	function setClientSubCategories() {
		$sub_categories = SubCategory::where('user_id', $this->userId())
								->get();

		$index = count($sub_categories);
		foreach ($sub_categories as $sub_category) {
			$client_category = Category::where('user_id', $this->client->comercio_city_user_id)
										->where('provider_category_id', $sub_category->category_id)
										->first();
			SubCategory::create([
				'num'						=> $this->num('sub_categories', $this->inventory_linkage->client->comercio_city_user_id),
				'name'						=> $sub_category->name,
				'category_id'				=> $client_category->id,
				'user_id'					=> $this->inventory_linkage->client->comercio_city_user_id,
				'provider_sub_category_id'	=> $sub_category->id,
				'created_at'				=> Carbon::now()->subSeconds($index),
			]);
			$index--;
		}
	}
	
	function setClientArticles() {
		$articles = Article::where('user_id', $this->userId())
							// ->where('id', '>', 102275)
							->orderBy('id', 'ASC')
							->get();

		$index = count($articles);
		foreach ($articles as $article) {
			$created_at = Carbon::now()->subSeconds($index);
			$this->createClientArticle($article, $created_at);
			$index--;
			// $client_article = Article::where('provider_article_id', $article->id)
			// 							->first();
			// if (is_null($client_article)) {
			// 	$created_at = Carbon::now()->subSeconds($index);
			// 	$this->createClientArticle($article, $created_at);
			// 	$index--;
			// }
		}
	}

	function checkArticle($article) {
		$inventory_linkages = InventoryLinkage::where('user_id', $this->user_id)
												->get();
		if (count($inventory_linkages) >= 1) {
			foreach ($inventory_linkages as $inventory_linkage) {
				$this->inventory_linkage = $inventory_linkage;
				$this->client = $inventory_linkage->client;
				$this->setProviderForClient();
				// Log::info('Entro en la vinculacion del cliente '.$client->name);
				// Log::info('Buscando article con user_id = '.$client->comercio_city_user_id.' y provider_article_id = '.$article->id);
				$client_article = Article::where('user_id', $this->client->comercio_city_user_id)
												->where('provider_article_id', $article->id)
												->withTrashed()
												->first();
				if (!is_null($client_article)) {
					Log::info('Habia article');
					if (is_null($client_article->deleted_at)) {
						// Log::info('Costo nuevo: '.$this->getClientArticlePrice($article));
						$client_article->cost = $this->getClientArticlePrice($article);
						
						$client_article->category_id = $this->getClientCategoryId($article);
						$client_article->sub_category_id = $this->getClientSubCategoryId($article);
						$client_article->save();
						ArticleHelper::setFinalPrice($client_article, $this->client->comercio_city_user_id);
					} 
				} else {
					Log::info('No habia article');
					$client_article = $this->createClientArticle($article);
				}
				// $this->sendAddModelNotification('article', $client_article->id, false, $this->client->comercio_city_user_id);
			}
		}
	}

	function createClientArticle($article, $created_at = null) {
		if (is_null($created_at)) {
			$created_at = Carbon::now();
		}
		$price = $this->getClientArticlePrice($article);

		$client_article = Article::create([
			'num'					=> $this->num('articles', $this->client->comercio_city_user_id),
			'bar_code'				=> $article->bar_code,
			'provider_code'			=> $article->provider_code,
			'category_id'			=> $this->getClientCategoryId($article),
			'sub_category_id'		=> $this->getClientSubCategoryId($article),
			'name'					=> $article->name,
			'slug'					=> $article->slug,
			'cost'					=> $price,
			'iva_id'				=> $article->iva_id,
			'provider_id'			=> $this->provider_for_client->id,
			'provider_article_id'	=> $article->id,
			'user_id'				=> $this->client->comercio_city_user_id,
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
		ArticleHelper::setFinalPrice($client_article, $this->client->comercio_city_user_id);
		return $client_article;
	}

	function check_created_image($article, $created_image) {

		$inventory_linkages = InventoryLinkage::where('user_id', $this->user_id)
												->get();
		if (count($inventory_linkages) >= 1) {
			foreach ($inventory_linkages as $inventory_linkage) {
				$client = $inventory_linkage->client;

				$client_article = Article::where('user_id', $client->comercio_city_user_id)
												->where('provider_article_id', $article->id)
												->first();

				if (!is_null($client_article)) {
		            $image = Image::create([
		                env('IMAGE_URL_PROP_NAME', 'image_url')     => $created_image->{env('IMAGE_URL_PROP_NAME', 'image_url')},
		                'imageable_id'                              => $client_article->id,
		                'imageable_type'                            => 'article',
		                'temporal_id'                               => null,
		            ]);

					$this->sendAddModelNotification('article', $client_article->id, false, $client->comercio_city_user_id);
				}
			}
		}
	}

	function delete_image($image_to_delete) {

		if ($image_to_delete->imageable_type == 'article') {
			$article = Article::find($image_to_delete->imageable_id);

			if (!is_null($article)) {

				$inventory_linkages = InventoryLinkage::where('user_id', $this->user_id)
														->get();
				if (count($inventory_linkages) >= 1) {
					foreach ($inventory_linkages as $inventory_linkage) {
						$client = $inventory_linkage->client;

						$client_article = Article::where('user_id', $client->comercio_city_user_id)
														->where('provider_article_id', $article->id)
														->first();

						if (!is_null($client_article)) {
							$image = Image::where(env('IMAGE_URL_PROP_NAME', 'image_url'), $image_to_delete->{env('IMAGE_URL_PROP_NAME', 'image_url')})
											->where('imageable_id', $client_article->id)
											->first();

							if (!is_null($image)) {
								$image->delete();
								Log::info('Se eliminio imagen del cliente en linkage');
							}
						}
					}
				}
			}
		}

	}

	function check_is_agotado($article) {

		if (!is_null($article->stock)) {
			$inventory_linkages = InventoryLinkage::where('user_id', $this->user_id)
													->get();
			if (count($inventory_linkages) >= 1) {
				foreach ($inventory_linkages as $inventory_linkage) {
					$client = $inventory_linkage->client;

					$client_article = Article::where('user_id', $client->comercio_city_user_id)
													->where('provider_article_id', $article->id)
													->first();

					if (!is_null($client_article)) {

						$save = false;

						// Si el stock del proveedor es 0, se setea el del cliente como agotado
						if ($article->stock <= 0 && ($client_article->stock != 0 || $client_article->stock == '')) {
			            	$client_article->stock = 0;
							$save = true;

						// Si el stock del proveedor no es menor que 0, y del cliente ya estaba agotado, se setea el del cliente como disponible
						} else if ($article->stock > 0 && $client_article->stock <= 0) {
			            	$client_article->stock = null;
							$save = true;
						}

						if ($save) {
				            $client_article->save();
							// $this->sendAddModelNotification('article', $client_article->id, false, $client->comercio_city_user_id);
						}
					}
				}
			}
		}


	}

	function getClientCategoryId($article) {
		if (!is_null($article->category_id)) {
			$category = Category::where('user_id', $this->client->comercio_city_user_id)
								->where('provider_category_id', $article->category_id)
								->first();
			if (!is_null($category)) {
				return $category->id;
			} else {
				$provider_category = Category::find($article->category_id);
				if (!is_null($provider_category)) {
					$category = Category::create([
						'num'					=> $this->num('categories', $this->inventory_linkage->client->comercio_city_user_id),
						'name'					=> $provider_category->name,
						'image_url'				=> $provider_category->image_url,
						'user_id'				=> $this->inventory_linkage->client->comercio_city_user_id,
						'provider_category_id'	=> $provider_category->id,
					]);
					return $category->id;
				}
			}
		}
		return null;
	}

	function getClientSubCategoryId($article) {
		if (!is_null($article->sub_category_id)) {
			$sub_category = SubCategory::where('user_id', $this->client->comercio_city_user_id)
									->where('provider_sub_category_id', $article->sub_category_id)
									->first();
			if (!is_null($sub_category)) {
				return $sub_category->id;
			} else {
				$provider_sub_category = SubCategory::find($article->sub_category_id);
				if (!is_null($provider_sub_category)) {

					$client_category = Category::where('user_id', $this->client->comercio_city_user_id)
												->where('provider_category_id', $provider_sub_category->category_id)
												->first();
					$sub_category = SubCategory::create([
						'num'						=> $this->num('sub_categories', $this->inventory_linkage->client->comercio_city_user_id),
						'name'						=> $provider_sub_category->name,
						'category_id'				=> $client_category->id,
						'user_id'					=> $this->inventory_linkage->client->comercio_city_user_id,
						'provider_sub_category_id'	=> $provider_sub_category->id,
					]);
					return $sub_category->id;
				}
			}
		}
		return null;
	}

	function getClientArticlePrice($article) {
		$price = $article->final_price;
		// Log::info('El articulo N° '.$article->num.', nombre: '.$article->name.' con el precio: '.$article->final_price);
		Log::info($article);
		if (count($this->price_types) >= 1) {

			$client_price_type = $this->price_types[count($this->price_types)-1];  
			if (!is_null($this->client->price_type)) {
				$client_price_type = $this->client->price_type;
			}

			foreach ($this->price_types as $price_type) {
				if ($price_type->position <= $client_price_type->position) {
					$percentage = $price_type->percentage;
                    if (count($price_type->sub_categories) >= 1 && !is_null($article->sub_category)) {
                        foreach ($price_type->sub_categories as $price_type_sub_category) {
                            if ($price_type_sub_category->id == $article->sub_category_id) {
                                // Log::info('Usando el porcetaje de '.$price_type_sub_category->name.' de '.$price_type_sub_category->pivot->percentage);
                                $percentage = $price_type_sub_category->pivot->percentage;
                            }
                        }
                    }
					$price = $price + ($price * $percentage / 100);
					// Log::info('Sumando el '.$percentage.' de '.$price_type->name.'. Quedo en: '.$price);
				}
			}
		}

		foreach ($this->inventory_linkage->categories as $category) {
			if ($category->id == $article->category_id) {
				// Log::info('inventory_linkage con la category '.$category->name);
				// Log::info('El precio estaba en '.$price);
				$price -= $price * $category->pivot->percentage_discount / 100;
				// Log::info('Quedo en '.$price);
			}
		}
		// Log::info('return '.$price);
		return $price;
	}

}