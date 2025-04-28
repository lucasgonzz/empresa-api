<?php

namespace App\Http\Controllers\Helpers\Excel\Article;

use App\Http\Controllers\Helpers\UserHelper;
use App\Models\PriceType;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

class ArticleClientsExportHelper {
	
	static function set_price_types_headings($headings, $price_type_id = null) {
		
		$price_types = Self::getPriceTypes($price_type_id);

		$mostrar_diferencia_de_precios = UserHelper::hasExtencion('mostrar_diferenia_de_precios_en_excel_para_clientes');

		$columnas_cambio_precio = [];

		foreach ($price_types as $price_type) {
			
			$headings[] = $price_type->name;

			if ($mostrar_diferencia_de_precios) {
				$headings[] = 'Diferencia';

		        $index = count($headings); // columna actual (1-based)
		        $columnas_cambio_precio[] = Coordinate::stringFromColumnIndex($index);
			}
		}

		return [
			'headings'	=> $headings,
			'columnas_cambio_precio'	=> $columnas_cambio_precio,
		];
	}

	
	static function map_price_types($map, $article, $price_type_id = null) {

		$price_types = Self::getPriceTypes($price_type_id);

		$mostrar_diferencia_de_precios = UserHelper::hasExtencion('mostrar_diferenia_de_precios_en_excel_para_clientes');

		foreach ($price_types as $price_type) {

			$article_price_type = $article->price_types()->find($price_type->id);

			if ($article_price_type) {
				
				$map[] = $article_price_type->pivot->final_price;

				if ($mostrar_diferencia_de_precios) {
					$map[] = Self::get_diferencia_de_precio($article_price_type);
				}
			}

		}

		return $map;
	}

	static function get_diferencia_de_precio($price_type) {

		$previus_price = $price_type->pivot->previus_final_price;

		if (!is_null($previus_price)) {
			$actual_price = $price_type->pivot->final_price;

			if ($actual_price > $previus_price) {
				return 'Aumento';
			} else if ($actual_price < $previus_price) {
				return 'Disminuyo';
			}
		}

		return null;
	}



	static function getPriceTypes($price_type_id) {
		$models = PriceType::where('user_id', UserHelper::userId());

		if ($price_type_id) {
			$models = $models->where('id', $price_type_id);
		}
		
		$models = $models->whereNotNull('position')
						->orderBy('position', 'ASC')
						->get();
		return $models;
	}

}