<?php

namespace App\Http\Controllers\Helpers;
use App\Http\Controllers\CommonLaravel\Helpers\ImportHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Helpers\category\SetPriceTypesHelper;
use App\Models\Brand;
use App\Models\Category;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\Iva;
use App\Models\SubCategory;

class LocalImportHelper {

	static function setSaldoInicial($row, $columns, $model_name, $model) {

		$saldo_actual = ImportHelper::getColumnValue($row, 'saldo_actual', $columns);
        
        if (!is_null($saldo_actual)) {

        	$credit_account = CreditAccount::where('model_name', $model_name)
        									->where('model_id', $model->id)
        									->where('moneda_id', 1)
        									->first();

            $current_acounts = CurrentAcount::where('credit_account_id', $credit_account->id)
                                            ->get();

            if (count($current_acounts) == 0) {
            	
                $is_for_debe = false;
                $saldo_inicial = (float)$saldo_actual;
                if ($saldo_inicial >= 0) {
                    $is_for_debe = true;
                }

                $current_acount = CurrentAcount::create([
                    'detalle'   => 'Saldo inicial',
                    'status'    => $is_for_debe ? 'sin_pagar' : 'pago_from_client',
                    'client_id' => $model_name == 'client' ? $model->id : null,
                    'provider_id' => $model_name == 'provider' ? $model->id : null,
                    'debe'      => $is_for_debe ? $saldo_inicial : null,
                    'haber'     => !$is_for_debe ? $saldo_inicial : null,
                    'credit_account_id'	=> $credit_account->id,
                    'saldo'     => $saldo_inicial,
                ]);

                $model->saldo = $saldo_inicial;
                $model->save();
            }
        }
	}

	// static function setSaldoInicial($row, $columns, $model_name, $model) {

	// 	$saldo_actual = ImportHelper::getColumnValue($row, 'saldo_actual', $columns);
        
    //     if (!is_null($saldo_actual)) {

    //         $current_acounts = CurrentAcount::where($model_name.'_id', $model->id)
    //                                         ->get();

    //         if (count($current_acounts) == 0) {
            	
    //             $is_for_debe = false;
    //             $saldo_inicial = (float)$saldo_actual;
    //             if ($saldo_inicial >= 0) {
    //                 $is_for_debe = true;
    //             }
    //             $current_acount = CurrentAcount::create([
    //                 'detalle'   => 'Saldo inicial',
    //                 'status'    => $is_for_debe ? 'sin_pagar' : 'pago_from_client',
    //                 'client_id' => $model_name == 'client' ? $model->id : null,
    //                 'provider_id' => $model_name == 'provider' ? $model->id : null,
    //                 'debe'      => $is_for_debe ? $saldo_inicial : null,
    //                 'haber'     => !$is_for_debe ? $saldo_inicial : null,
    //                 'saldo'     => $saldo_inicial,
    //             ]);
    //             $model->saldo = $saldo_inicial;
    //             $model->save();
    //         }
    //     }
	// }

	static function get_bran_id($brand_excel, $ct, $owner) {
		if ($brand_excel != '') {
			
			$brand = Brand::where('user_id', $owner->id)
								->where('name', $brand_excel)
								->first();

			if (is_null($brand)) {
				$brand = Brand::create([
	        		// 'num'		=> $ct->num('categories', $owner->id, 'user_id', $owner->id),
					'name' 		=> $brand_excel,
					'user_id' 	=> $owner->id,
				]);
			}
			return $brand->id;
		}
		return null;
	}

	static function getCategoryId($categoria, $ct, $owner) {
		if ($categoria != '') {
			$category = Category::where('user_id', $owner->id)
								->where('name', $categoria)
								->first();
			if (is_null($category)) {
				$category = Category::create([
	        		'num'		=> $ct->num('categories', $owner->id, 'user_id', $owner->id),
					'name' 		=> $categoria,
					'user_id' 	=> $owner->id,
				]);


				SetPriceTypesHelper::set_price_types($category, $owner);
				SetPriceTypesHelper::set_rangos($category, $owner);
				
			}
			return $category->id;
		}
		return null;
	}

	static function getSubcategoryId($categoria, $sub_categoria, $ct, $owner) {
		if ($categoria != '' && $sub_categoria != '') {
			$category = Category::where('user_id', $owner->id)
								->where('name', $categoria)
								->first();
			
			$sub_category = SubCategory::where('user_id', $owner->id)
										->where('name', $sub_categoria)
										->where('category_id', $category->id)
										->first();
			if (is_null($sub_category)) {
				$sub_category = SubCategory::create([
	        		'num'			=> $ct->num('sub_categories', $owner->id, 'user_id', $owner->id),
					'name' 			=> $sub_categoria,
					'category_id' 	=> $category->id,
					'user_id'		=> $owner->id,
				]);

				if (UserHelper::hasExtencion('lista_de_precios_por_categoria', $owner)) {

					SetPriceTypesHelper::set_price_types($sub_category, $owner);
				}
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

	static function saveProvider($proveedor, $ct, $owner) {
		if ($proveedor != 'Sin especificar' && $proveedor != '') {
	        $data = [
	        	'num'		=> $ct->num('providers', $owner->id, 'user_id', $owner->id),
                'name'      => $proveedor,
                'user_id'   => $owner->id,
            ];
	        $ct->createIfNotExist('providers', 'name', $proveedor, $data, true, $owner->id);
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