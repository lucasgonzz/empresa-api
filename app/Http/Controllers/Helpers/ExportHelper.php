<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\CommonLaravel\Helpers\UserHelper;
use App\Models\Address;
use App\Models\Article;
use App\Models\PriceType;
use App\Models\Sale;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

class ExportHelper {

	static function getPriceTypes() {
		return PriceType::where('user_id', UserHelper::userId())
								->whereNotNull('position')
								->orderBy('position', 'ASC')
								->get();
	}

	static function getAddresses() {
		return Address::where('user_id', UserHelper::userId())
						->orderBy('created_at', 'ASC')
						->get();
	}

	static function mapAddresses($map, $article) {
		$addresses = Self::getAddresses();
		if (count($addresses) >= 1) {
			foreach ($addresses as $address) {
				$map[] = $article->{$address->street};
			}
		}
		return $map;
	}
	
	static function mapPriceTypes($map, $article) {
		$price_types = Self::getPriceTypes();
		if (count($price_types) >= 1) {
			foreach ($price_types as $price_type) {
				$map[] = $article->{$price_type->name};
			}
		}
		return $map;
	}

	static function setAddressesHeadings($headings) {
		$addresses = Self::getAddresses();
		if (count($addresses) >= 1) {
			foreach ($addresses as $address) {
				$headings[] = $address->street;
			}
		}
		return $headings;
	}
	
	static function setPriceTypesHeadings($headings) {
		$price_types = Self::getPriceTypes();
		if (count($price_types) >= 1) {
			foreach ($price_types as $price_type) {
				$headings[] = $price_type->name;
			}
		}
		return $headings;
	}

	static function setAddresses($articles) {
		$addresses = Self::getAddresses();
		if (count($addresses) >= 1) {
			foreach ($articles as $article) {
				foreach ($addresses as $address) {
					$stock_address = null;
					foreach ($article->addresses as $article_address) {
						if ($article_address->id == $address->id) {
							$stock_address = $article_address->pivot->amount;
						}
					}
					$article->{$address->street} = $stock_address;
				}
			}
		}
		return $articles;
	}
	
	
	static function setPriceTypes($articles) {
		$price_types = Self::getPriceTypes();
		if (count($price_types) >= 1) {
			foreach ($articles as $article) {
				$price = $article->final_price;
				foreach ($price_types as $price_type) {
					$price = $price + ($price * $price_type->percentage / 100);
					$article->{$price_type->name} = $price; 
				}
			}
		}
		return $articles;
	}
	
}