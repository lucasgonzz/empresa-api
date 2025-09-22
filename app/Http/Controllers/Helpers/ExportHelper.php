<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\Helpers\UserHelper;
use App\Models\Address;
use App\Models\Article;
use App\Models\ArticlePropertyType;
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
						->orderBy('created_at', 'DESC')
						->get();
	}

	static function getPropertyTypes() {
		return ArticlePropertyType::orderBy('created_at', 'DESC')
						->get();
	}

	static function map_property_types($map, $article) {
			
		$models = Self::getPropertyTypes();
		if (count($models) >= 1) {

			foreach ($models as $property_type) {
				$variant_property_value = $article->variant->article_property_values->where('article_property_type_id', $property_type->id)->first();
				
				if ($variant_property_value) {

					$map[] = $variant_property_value->name;
				} else {
					$map[] = '';
				}
			}
		}


		return $map;
	}

	static function map_property_types_vacios($map) {
			
		$models = Self::getPropertyTypes();
		if (count($models) >= 1) {

			foreach ($models as $property_type) {

				$map[] = '';
			}
		}


		return $map;
	}

	static function map_variant_stock_addresses($map, $article) {
			
		$models = Self::getAddresses();
		if (
			count($models) >= 1
		) {

			foreach ($models as $address) {

				$variant_address = $article->variant->addresses->find($address->id);
				
				if ($variant_address) {

					$map[] = $variant_address->pivot->amount;
				} else {
					$map[] = '';
				}
			}
		}


		return $map;
	}

	static function map_unidades_individuales($map, $article) {
		
		if (UserHelper::hasExtencion('articulos_unidades_individuales')) {

			$map[] = $article->unidades_individuales;
		}

		return $map;
	}

	static function map_autopartes($map, $article) {
		
		if (UserHelper::hasExtencion('autopartes')) {

			$map[] = $article->espesor;
			$map[] = $article->modelo;
			$map[] = $article->pastilla;
			$map[] = $article->diametro;
			$map[] = $article->litros;
			// $map[] = $article->descripcion;
			$map[] = $article->contenido;
			$map[] = $article->cm3;
			$map[] = $article->calipers;
			$map[] = $article->juego;

		}

		return $map;
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
				$map[] = $article->{'stock_min_'.$address->street};
				$map[] = $article->{'stock_max_'.$address->street};
			}
		}
		return $map;
	}
	
	static function mapPriceTypes($map, $article) {
		$price_types = Self::getPriceTypes();

		if (count($price_types) >= 1) {

			if (UserHelper::hasExtencion('articulo_margen_de_ganancia_segun_lista_de_precios')) {
				Log::info('articulo_margen_de_ganancia_segun_lista_de_precios');
				
				// Caso Pack descartables
				foreach ($price_types as $price_type) {

					$article_price_type = $article->price_types()->find($price_type->id);

					if ($article_price_type) {

						$setear = $article_price_type->pivot->setear_precio_final;

						if (
							$setear == 1
							|| $setear == true
						) {
							$setear = 'Si';
						} else {
							$setear = 'No';
						}
						
						$map[] = $setear;
						$map[] = $article_price_type->pivot->percentage;
						$map[] = $article_price_type->pivot->final_price;
					}

				}

			} else if (UserHelper::hasExtencion('lista_de_precios_por_categoria')) {
				
				// Caso Golo_norte

				$price_types_ordenados = $article->price_types()->orderBy('position', 'ASC')->get();
				foreach ($price_types_ordenados as $price_type) {
					
					// dd($price_type->pivot->final_price);

					$map[] = $price_type->pivot->final_price;
				}

			} else {
				
				// Caso Colman

				foreach ($price_types as $price_type) {
					$map[] = $article->{$price_type->name};
				}
			}

		}
		return $map;
	}
	
	static function mapDates($map, $article) {

		$map[] = $article->created_at;
		$map[] = $article->updated_at;
			
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

	static function set_unidades_individuales($headings) {
		if (UserHelper::hasExtencion('articulos_unidades_individuales')) {

				$headings[] = 'U Individuales';
		}

		return $headings;
	}

	static function set_props_autopartes($headings) {
		if (UserHelper::hasExtencion('autopartes')) {

				$headings[] = 'espesor';
				$headings[] = 'modelo';
				$headings[] = 'pastilla';
				$headings[] = 'diametro';
				$headings[] = 'litros';
				// $headings[] = 'descripcion';
				$headings[] = 'contenido';
				$headings[] = 'cm3';
				$headings[] = 'calipers';
				$headings[] = 'juego';
		}

		return $headings;
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
				$headings[] = 'Min '.$address->street;
				$headings[] = 'Max '.$address->street;
			}
		}
		return $headings;
	}

	static function setPropertyTypesHeadings($headings) {

		if (UserHelper::hasExtencion('article_variants')) {
			$models = Self::getPropertyTypes();
			if (count($models) >= 1) {
				foreach ($models as $property_type) {
					$headings[] = $property_type->name;
				}
			}
			return $headings;
		}
	}

	static function setPriceTypesHeadings($headings) {
		$price_types = Self::getPriceTypes();
		if (count($price_types) >= 1) {

			foreach ($price_types as $price_type) {

				if (UserHelper::hasExtencion('articulo_margen_de_ganancia_segun_lista_de_precios')) {

					// $headings[] = '% '.$price_type->name;
					$headings[] = 'Setear precio final '.$price_type->name;
					$headings[] = '% '.$price_type->name;
					$headings[] = '$ Final '.$price_type->name;
				} else {

					$headings[] = $price_type->name;
				}
			}

		}
		return $headings;
	}

	static function setDatesHeadings($headings) {

		$headings[] = 'Creado';
		$headings[] = 'Actualizado';

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
					$stock_min = null;
					$stock_max = null;
					
					foreach ($article->addresses as $article_address) {
						if ($article_address->id == $address->id) {
							$stock_address = $article_address->pivot->amount;
							$stock_min = $article_address->pivot->stock_min;
							$stock_max = $article_address->pivot->stock_max;
						}
					}
					
					$article->{$address->street} = $stock_address;
					$article->{'stock_min_'.$address->street} = $stock_min;
					$article->{'stock_max_'.$address->street} = $stock_max;
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

	  	$article->discounts_percentage_formated = '';
	  	$article->discounts_amount_formated = '';

        if (count($article->article_discounts) >= 1) {

            foreach ($article->article_discounts as $discount) {
                
                if (!is_null($discount->percentage)) {
                	$article->discounts_percentage_formated .= $discount->percentage.'_';
                } else if (!is_null($discount->amount)) {
                	$article->discounts_amount_formated .= $discount->amount.'_';
                }
            }

            // Limpio el ultimo _ que se agrego en el foreach
            $article->discounts_percentage_formated = substr($article->discounts_percentage_formated, 0, strlen($article->discounts_percentage_formated)-1);
           
            $article->discounts_amount_formated = substr($article->discounts_amount_formated, 0, strlen($article->discounts_amount_formated)-1);
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

	  	$article->surchages_percentage_formated = '';
	  	$article->surchages_amount_formated = '';

        if (count($article->article_surchages) >= 1) {
            foreach ($article->article_surchages as $surchage) {

            	Log::info('Recargo de '.$article->name.' luego_del_precio_final: '.$surchage->luego_del_precio_final);
            	
            	if (!is_null($surchage->percentage)) {
                	
	        		if ($surchage->luego_del_precio_final) {
	            		$article->surchages_percentage_formated .= 'F';
	        		} 
                	$article->surchages_percentage_formated .= $surchage->percentage.'_';
					Log::info('percentage: '.$surchage->percentage);            	
            	} else if (!is_null($surchage->amount)) {

	        		if ($surchage->luego_del_precio_final) {
	            		$article->surchages_amount_formated .= 'F';
	        		} 
                	$article->surchages_amount_formated .= $surchage->amount.'_';
					Log::info('amount: '.$surchage->amount);            	
            	}
            }

        	Log::info('Quedo asi: '.$article->surchages_percentage_formated);

            $article->surchages_percentage_formated = substr($article->surchages_percentage_formated, 0, strlen($article->surchages_percentage_formated)-1);
            $article->surchages_amount_formated = substr($article->surchages_amount_formated, 0, strlen($article->surchages_amount_formated)-1);
        	Log::info('Y despyes asi: '.$article->surchages_percentage_formated);
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