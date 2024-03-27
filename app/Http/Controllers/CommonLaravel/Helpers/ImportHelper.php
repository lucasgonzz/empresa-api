<?php

namespace App\Http\Controllers\CommonLaravel\Helpers;
use Illuminate\Support\Facades\Log;

class ImportHelper {

	static function getColumnValue($row, $key, $columns) {
		// Log::info('--------------------------------');
		// Log::info('Por buscar el indice '.$key.' en las columns:');
		// Log::info($columns);
		if (isset($columns[$key]) && isset($row[$columns[$key]])) {
			// Log::info('Estaba el indice con el valor: '.$columns[$key]);
			// Log::info('row: ');
			// Log::info($row);
			// Log::info('Y row tenia: '.$row[$columns[$key]]);
			return $row[$columns[$key]];
		}
		// Log::info('No estaba el indice');
		return null;
	}

	static function isIgnoredColumn($key, $columns) {
		if (!isset($columns[$key])) {
			// Log::info('Se ignora '.$key);
			return true;
		} else {
			return false;
		}
	}

}