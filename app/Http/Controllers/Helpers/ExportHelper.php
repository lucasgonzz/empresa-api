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
						->orderBy('id', 'ASC')
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
		if (UserHelper::hasExtencion('propiedades_de_distribuidora')) {

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

	/**
	 * Elimina columnas del $map usando los índices encontrados por título en $headings.
	 * Sirve para mantener map alineado con headings cuando headings elimina columnas.
	 */
	static function unset_map_columns_by_titles($map, $headings, $titles) {

		foreach ($titles as $title) {

			$index = array_search($title, $headings);

			if ($index !== false && array_key_exists($index, $map)) {
				unset($map[$index]);
			}
		}

		return array_values($map);
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

			if (UserHelper::uses_listas_de_precio()) {

				if (UserHelper::hasExtencion('ventas_en_dolares')) {

					foreach ($article->price_type_monedas as $price_type_moneda) {

						$setear = $price_type_moneda->setear_precio_final;

						if (
							$setear == 1
							|| $setear == true
						) {
							$setear = 'Si';
						} else {
							$setear = 'No';
						}
						
						$map[] = $setear;
						$map[] = $price_type_moneda->percentage;
						$map[] = $price_type_moneda->final_price;

						// dd($map);

					}
				} else {

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
				}


			} else if (UserHelper::hasExtencion('lista_de_precios_por_categoria')) {
				
				// Caso Golo_norte

				$price_types_ordenados = $article->price_types()->orderBy('position', 'ASC')->get();
				foreach ($price_types_ordenados as $price_type) {

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
		if (UserHelper::hasExtencion('propiedades_de_distribuidora')) {

				$headings[] = 'Tipo envase';
				$headings[] = 'Contenido';
				$headings[] = 'Unidades por bulto';
		}
		return $headings;
	}

	static function setAddresses($articles) {
		$addresses = Self::getAddresses();
		if (count($addresses) >= 1) {

			foreach ($addresses as $address) {
				foreach ($articles as $article) {

					$article_address = $article->addresses()->find($address->id);
					if ($article_address) {
						$article->{$address->street} = $article_address->pivot->amount;
						$article->{'stock_min_'.$address->street} = $article_address->pivot->stock_min;
						$article->{'stock_max_'.$address->street} = $article_address->pivot->stock_max;
					}
					// $article->setRelation('addresses', $article->addresses()->orderBy('id', 'ASC')->get());
				}
			}
		}
		return $articles;
	}

	static function setAddressesHeadings($headings) {
		$addresses = Self::getAddresses();
		if (count($addresses) >= 1) {

			$stock_index = array_search('Stock actual', $headings);
			$stock_min_index = array_search('Stock minimo', $headings);

			unset($headings[$stock_index]);
			unset($headings[$stock_min_index]);
			
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
		}
		return $headings;
	}

	static function setPriceTypesHeadings($headings) {
		$price_types = Self::getPriceTypes();
		if (count($price_types) >= 1) {

			Log::info('setPriceTypesHeadings');

			$margen_ganancia_index = array_search('Margen de ganancia', $headings);
			$precio_index = array_search('Precio', $headings);
			$precio_final_index = array_search('Precio Final', $headings);

			unset($headings[$margen_ganancia_index]);
			unset($headings[$precio_index]);
			unset($headings[$precio_final_index]);

			$aplicar_iva_index = array_search('Aplicar Iva', $headings);

			// Lo aumento para que se inserten luego de este indice
			$aplicar_iva_index++;

			foreach ($price_types as $price_type) {
				
				// dd($price_type->name);

				if (UserHelper::uses_listas_de_precio()) {

					array_splice($headings, $aplicar_iva_index, 0, '$ Final '.$price_type->name);
					array_splice($headings, $aplicar_iva_index, 0, '% '.$price_type->name);
					array_splice($headings, $aplicar_iva_index, 0, 'Setear precio final '.$price_type->name);
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

	// static function setAddresses($articles) {
	// 	$addresses = Self::getAddresses();
	// 	if (count($addresses) >= 1) {
			

	// 		foreach ($articles as $article) {
	
	// 			foreach ($addresses as $address) {
					
	// 				$stock_address = null;
	// 				$stock_min = null;
	// 				$stock_max = null;
					
	// 				foreach ($article->addresses as $article_address) {
	// 					if ($article_address->id == $address->id) {
	// 						$stock_address = $article_address->pivot->amount;
	// 						$stock_min = $article_address->pivot->stock_min;
	// 						$stock_max = $article_address->pivot->stock_max;
	// 					}
	// 				}
					
	// 				$article->{$address->street} = $stock_address;
	// 				$article->{'stock_min_'.$address->street} = $stock_min;
	// 				$article->{'stock_max_'.$address->street} = $stock_max;
	// 			}
	// 		}
	// 	}
	// 	return $articles;
	// }
	
	
	static function setPriceTypes($articles) {
		$price_types = Self::getPriceTypes();
		if (count($price_types) >= 1) {
			foreach ($articles as $article) {
				$article->setRelation('price_types', $article->price_types()->orderBy('position', 'ASC')->get());
			}
		}
		return $articles;
	}
	
	
	static function set_descuentos_y_recargos($articles) {

		foreach ($articles as $article) {

			// Descuentos y recargos en negro
			$article->discounts_percentage_formated = '';
			$article->surchages_percentage_formated = '';
			$article->discounts_amount_formated = '';
			$article->surchages_amount_formated = '';

			if (count($article->article_discounts) >= 1) {
				foreach ($article->article_discounts as $discount) {
					$article->discounts_percentage_formated .= $discount->percentage.'_';
				}
				$article->discounts_percentage_formated = substr($article->discounts_percentage_formated, 0, strlen($article->discounts_percentage_formated)-1);
			}

			if (count($article->article_surchages) >= 1) {
				foreach ($article->article_surchages as $surchage) {
					$article->surchages_percentage_formated .= $surchage->percentage.'_';
				}
				$article->surchages_percentage_formated = substr($article->surchages_percentage_formated, 0, strlen($article->surchages_percentage_formated)-1);
			}

			if (count($article->article_discounts) >= 1) {
				foreach ($article->article_discounts as $discount) {
					$article->discounts_amount_formated .= $discount->amount.'_';
				}
				$article->discounts_amount_formated = substr($article->discounts_amount_formated, 0, strlen($article->discounts_amount_formated)-1);
			}

			if (count($article->article_surchages) >= 1) {
				foreach ($article->article_surchages as $surchage) {
					$article->surchages_amount_formated .= $surchage->amount.'_';
				}
				$article->surchages_amount_formated = substr($article->surchages_amount_formated, 0, strlen($article->surchages_amount_formated)-1);
			}

			// Descuentos y recargos en blanco
			$article->discounts_percentage_formated_blanco = '';
			$article->surchages_percentage_formated_blanco = '';
			$article->discounts_amount_formated_blanco = '';
			$article->surchages_amount_formated_blanco = '';

			if (count($article->article_discounts_blanco) >= 1) {
				foreach ($article->article_discounts_blanco as $discount) {
					$article->discounts_percentage_formated_blanco .= $discount->percentage.'_';
				}
				$article->discounts_percentage_formated_blanco = substr($article->discounts_percentage_formated_blanco, 0, strlen($article->discounts_percentage_formated_blanco)-1);
			}

			if (count($article->article_surchages_blanco) >= 1) {
				foreach ($article->article_surchages_blanco as $surchage) {
					$article->surchages_percentage_formated_blanco .= $surchage->percentage.'_';
				}
				$article->surchages_percentage_formated_blanco = substr($article->surchages_percentage_formated_blanco, 0, strlen($article->surchages_percentage_formated_blanco)-1);
			}

			if (count($article->article_discounts_blanco) >= 1) {
				foreach ($article->article_discounts_blanco as $discount) {
					$article->discounts_amount_formated_blanco .= $discount->amount.'_';
				}
				$article->discounts_amount_formated_blanco = substr($article->discounts_amount_formated_blanco, 0, strlen($article->discounts_amount_formated_blanco)-1);
			}

			if (count($article->article_surchages_blanco) >= 1) {
				foreach ($article->article_surchages_blanco as $surchage) {
					$article->surchages_amount_formated_blanco .= $surchage->amount.'_';
				}
				$article->surchages_amount_formated_blanco = substr($article->surchages_amount_formated_blanco, 0, strlen($article->surchages_amount_formated_blanco)-1);
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
                	
                	$article->surchages_percentage_formated .= $surchage->percentage;
	        		if ($surchage->luego_del_precio_final) {
	            		$article->surchages_percentage_formated .= 'F';
	        		} 
                	$article->surchages_percentage_formated .= '_';

					Log::info('percentage: '.$surchage->percentage);            	
            	} else if (!is_null($surchage->amount)) {

                	$article->surchages_amount_formated .= $surchage->amount;
	        		if ($surchage->luego_del_precio_final) {
	            		$article->surchages_amount_formated .= 'F';
	        		} 
                	$article->surchages_amount_formated .= '_';
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

	static function get_price_types_values_in_order($article) {

		$values = [];
		$price_types = Self::getPriceTypes();

		if (count($price_types) < 1) {
			return $values;
		}

		$price_types = $price_types->reverse();

		if (UserHelper::uses_listas_de_precio()) {

			if (UserHelper::hasExtencion('ventas_en_dolares')) {


				// IMPORTANTÍSIMO: iterar SIEMPRE por $price_types (ordenados por position)
				// Agrego solo los valores en pesos
				foreach ($price_types as $price_type) {

					// dd($price_type->name);

					$price_type_moneda = $article->price_type_monedas
						? $article->price_type_monedas->where('price_type_id', $price_type->id)
													->where('moneda_id', 1)
													->first()
						: null;

					if ($price_type_moneda) {

						$setear = ($price_type_moneda->setear_precio_final) ? 'Si' : 'No';

						$values[] = $setear;
						$values[] = $price_type_moneda->percentage;
						$values[] = $price_type_moneda->final_price;
					} else {
						// 3 columnas por price type en este modo
						$values[] = '';
						$values[] = '';
						$values[] = '';
					}
				}

				return $values;
			}

			// Caso sin dólares: relación price_types pivot
			foreach ($price_types as $price_type) {

				$article_price_type = $article->price_types()->find($price_type->id);

				if ($article_price_type) {

					$setear = ($article_price_type->pivot->setear_precio_final) ? 'Si' : 'No';

					$values[] = $setear;
					$values[] = $article_price_type->pivot->percentage;
					$values[] = $article_price_type->pivot->final_price;
				} else {
					$values[] = '';
					$values[] = '';
					$values[] = '';
				}
			}

			return $values;
		}

		// Otros casos
		if (UserHelper::hasExtencion('lista_de_precios_por_categoria')) {

			$price_types_ordenados = $article->price_types()->orderBy('position', 'ASC')->get();
			foreach ($price_types_ordenados as $price_type) {
				$values[] = $price_type->pivot->final_price;
			}

			return $values;
		}

		foreach ($price_types as $price_type) {
			$values[] = $article->{$price_type->name};
		}

		return $values;
	}

}