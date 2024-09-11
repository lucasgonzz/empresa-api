<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\Helpers\UserHelper;
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

	static function map_propiedades_de_distribuidora($map, $article) {
		
		if (UserHelper::hasExtencion('articulos_con_propiedades_de_distribuidora')) {

			// Log::info('map_propiedades_de_distribuidora tipo_de_envase_id:');
			// Log::info($article->tipo_de_envase_id);
			if (!is_null($article->tipo_envase)) {

				$map[] = $article->tipo_envase->name;
			} else {
				$map[] = '';
			}

			$map[] = $article->contenido;

			$map[] = $article->unidades_por_bulto;
		}

		return $map;
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

			if (UserHelper::hasExtencion('articulo_margen_de_ganancia_segun_lista_de_precios')) {


				foreach ($price_types as $price_type) {

					$article_price_type = $article->price_types()->find($price_type->id);

					$map[] = $article_price_type->pivot->percentage;
					$map[] = $article_price_type->pivot->price;
					$map[] = $article_price_type->pivot->final_price;
				}

			} else {

				foreach ($price_types as $price_type) {
					$map[] = $article->{$price_type->name};
				}
			}

		}
		return $map;
	}
	
	static function mapPreciosBlanco($map, $article) {

		if (UserHelper::hasExtencion('articulos_precios_en_blanco')) {

			$map[] = $article->discounts_blanco_formated;
			$map[] = $article->surchages_blanco_formated;
			$map[] = $article->percentage_gain_blanco;
			$map[] = $article->final_price_blanco;
		}
			
		return $map;
	}

	static function set_propiedades_de_distribuidora($headings) {
		Log::info('set_propiedades_de_distribuidora');
		Log::info('has: '.UserHelper::hasExtencion('articulos_con_propiedades_de_distribuidora'));
		if (UserHelper::hasExtencion('articulos_con_propiedades_de_distribuidora')) {

				$headings[] = 'Tipo de envase';
				$headings[] = 'Contenido';
				$headings[] = 'U x Bulto';
		}

		return $headings;
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

				if (UserHelper::hasExtencion('articulo_margen_de_ganancia_segun_lista_de_precios')) {

					$headings[] = '% '.$price_type->name;
					$headings[] = '$ '.$price_type->name;
					$headings[] = '$ Final '.$price_type->name;
				} else {

					$headings[] = $price_type->name;
				}
			}

		}
		return $headings;
	}

	static function setPreciosBlancoHeadings($headings) {

		if (UserHelper::hasExtencion('articulos_precios_en_blanco')) {
			$headings[] = 'Descuentos EN BLANCO';
			$headings[] = 'Recargos EN BLANCO';
			$headings[] = 'Margen de ganancia EN BLANCO';
			$headings[] = 'Precio Final EN BLANCO';
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
		if (!UserHelper::hasExtencion('articulo_margen_de_ganancia_segun_lista_de_precios')) {

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
		} 

		return $articles;
	}
	
	static function set_descuentos_y_recargos($articles) {


        foreach ($articles as $article) {

        	$article = Self::set_article_discounts($article);

        	$article = Self::set_article_surchages($article);


			if (UserHelper::hasExtencion('articulos_precios_en_blanco')) {

        		$article = Self::set_article_discounts_blanco($article);
        		
        		$article = Self::set_article_surchages_blanco($article);
			} 
          
        }


        return $articles;



	}

	static function set_article_discounts($article) {

	  	$article->discounts_formated = '';

        if (count($article->article_discounts) >= 1) {
            foreach ($article->article_discounts as $discount) {
                $article->discounts_formated .= $discount->percentage.'_';
            }
            $article->discounts_formated = substr($article->discounts_formated, 0, strlen($article->discounts_formated)-1);
        }

        return $article;
	}

	static function set_article_discounts_blanco($article) {

	  	$article->discounts_blanco_formated = '';

        if (count($article->article_discounts_blanco) >= 1) {

            foreach ($article->article_discounts_blanco as $discount) {

                $article->discounts_blanco_formated .= $discount->percentage.'_';
            }

            $article->discounts_blanco_formated = substr($article->discounts_blanco_formated, 0, strlen($article->discounts_blanco_formated)-1);
        }

        return $article;
	}

	static function set_article_surchages($article) {

	  	$article->surchages_formated = '';

        if (count($article->article_surchages) >= 1) {
            foreach ($article->article_surchages as $surchage) {

                $article->surchages_formated .= $surchage->percentage.'_';
            }

            $article->surchages_formated = substr($article->surchages_formated, 0, strlen($article->surchages_formated)-1);
        }

        return $article;
	}

	static function set_article_surchages_blanco($article) {

	  	$article->surchages_blanco_formated = '';

        if (count($article->article_surchages_blanco) >= 1) {
            foreach ($article->article_surchages_blanco as $surchage) {

                $article->surchages_blanco_formated .= $surchage->percentage.'_';
            }

            $article->surchages_blanco_formated = substr($article->surchages_blanco_formated, 0, strlen($article->surchages_blanco_formated)-1);
        }

        return $article;
	}

}