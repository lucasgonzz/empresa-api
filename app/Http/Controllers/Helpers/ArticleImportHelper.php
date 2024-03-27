<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\CommonLaravel\Helpers\UserHelper;
use App\Models\Address;
use App\Models\UnidadMedida;

class ArticleImportHelper {
	
	static function addAddressesColumns($columns) {
		$addresses = Address::where('user_id', UserHelper::userId())
							->orderBy('created_at', 'ASC')
							->get();
		$column_position = count($columns);

		// Le sumo 3 por las 2 columnas de creado y actualizado y 1 de precio final
		$column_position += 3;
		foreach ($addresses as $address) {
			$columns[$address->street] = $column_position;
			$column_position++;
		}
		return $columns;
	}

	static function get_unidad_medida_id($unidad_medida_excel) {
		$unidad_medida = null;

		if ($unidad_medida_excel == 'Rol') {
			$unidad_medida = 'Rollo';
		} else if ($unidad_medida_excel == 'UN') {
			$unidad_medida = 'Unidad';
		} else if ($unidad_medida_excel == 'C/U') {
			$unidad_medida = 'Unidad';
		} else if ($unidad_medida_excel == 'Mts') {
			$unidad_medida = 'Metro';
		}

		if (is_null($unidad_medida)) {
			$unidad_medida = $unidad_medida_excel;
		}
		
		$unidad_medida_store = UnidadMedida::where('name', $unidad_medida)
											->first();

		if (!is_null($unidad_medida_store)) {
			return $unidad_medida_store->id;
		}

		return 1;

	}
}