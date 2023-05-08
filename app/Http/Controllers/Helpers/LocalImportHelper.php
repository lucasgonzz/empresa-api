<?php

namespace App\Http\Controllers\Helpers;
use App\Http\Controllers\Helpers\UserHelper;
use App\Models\Category;
use App\Models\Iva;
use App\Models\SubCategory;

class LocalImportHelper {

	static function getCategoryId($categoria, $ct) {
		if ($categoria != '') {
			$category = Category::where('user_id', UserHelper::userId())
								->where('name', $categoria)
								->first();
			if (is_null($category)) {
				$category = Category::create([
					'num' 		=> $ct->num('categories'),
					'name' 		=> $categoria,
					'user_id' 	=> UserHelper::userId(),
				]);
			}
			return $category->id;
		}
		return null;
	}

	static function getSubcategoryId($categoria, $sub_categoria, $ct) {
		if ($categoria != '' && $sub_categoria != '') {
			$category = Category::where('user_id', UserHelper::userId())
								->where('name', $categoria)
								->first();
			// if (is_null($category)) {
			// 	$category = Category::create([
			// 		'num' 		=> $ct->num('categories'),
			// 		'name' 		=> $categoria,
			// 		'user_id' 	=> UserHelper::userId(),
			// 	]);
			// }
			$sub_category = SubCategory::where('user_id', UserHelper::userId())
										->where('name', $sub_categoria)
										->where('category_id', $category->id)
										->first();
			if (is_null($sub_category)) {
				$sub_category = SubCategory::create([
					'num' 			=> $ct->num('sub_categories'),
					'name' 			=> $sub_categoria,
					'category_id' 	=> $category->id,
					'user_id'		=> UserHelper::userId(),
				]);
			}
			return $sub_category->id;
		}
		return null;
	}

	static function saveLocation($localidad, $ct) {
		if (!is_null($localidad) && $localidad != 'Sin especificar') {
	        $data = [
                'name'      => $localidad,
                'user_id'   => $ct->userId(),
            ];
	        $ct->createIfNotExist('locations', 'name', $localidad, $data);
	    }
	}

	static function saveSeller($seller, $ct) {
		if (!is_null($seller) && $seller != 'Sin especificar') {
	        $data = [
	        	'num'		=> $ct->num('sellers'),
                'name'      => $seller,
                'user_id'   => $ct->userId(),
            ];
	        $ct->createIfNotExist('sellers', 'name', $seller, $data);
	    }
	}

	static function saveProvider($proveedor, $ct) {
		if ($proveedor != 'Sin especificar' && $proveedor != '') {
	        $data = [
	        	'num'		=> $ct->num('providers'),
                'name'      => $proveedor,
                'user_id'   => $ct->userId(),
            ];
	        $ct->createIfNotExist('providers', 'name', $proveedor, $data);
	    }
	}

	static function savePriceType($tipo_de_precio, $ct) {
		if ($tipo_de_precio != 'Sin especificar' && $tipo_de_precio != '') {
	        $data = [
                'name'      => $tipo_de_precio,
                'user_id'   => $ct->userId(),
            ];
	        $ct->createIfNotExist('price_types', 'name', $tipo_de_precio, $data);
	    }
	}

	static function getIvaId($iva, $article = null) {
		if (!is_null($iva)) {
			if ($iva != '' || $iva == '0' || $iva == 0) {
				$_iva = Iva::where('percentage', $iva)
							->first();
				if (is_null($_iva)) {
					$_iva = Iva::create([
						'percentage' => $iva,
					]);
				}
				return $_iva->id;
			}
		} else if (!is_null($article)) {
			if (!is_null($article->iva_id)) {
				return $article->iva_id;
			}
		}
		return 2;
	}
	
}