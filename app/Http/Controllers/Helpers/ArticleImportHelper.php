<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\CommonLaravel\Helpers\UserHelper;
use App\Models\Address;

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
}