<?php

namespace App\Http\Controllers\CommonLaravel\Helpers;
use Illuminate\Support\Facades\Log;

class ImportHelper {

	static function getColumnValue($row, $key, $columns) {
		if (
			isset($columns[$key]) 
			&& isset($row[$columns[$key]])
			&& $row[$columns[$key]] !== ''
			&& $row[$columns[$key]] !== -1
		) {
			$value = (string) $row[$columns[$key]];
			$value = str_replace("\xEF\xBB\xBF", '', $value);
			$value = trim($value);
			$value = trim($value, '"');
			return trim($value);
		}
		return null;
	}

	static function usa_columna($value) {
		return !is_null($value) && $value !== '';
	}

	static function isIgnoredColumn($key, $columns) {
		// Log::info('isIgnoredColumn para '.$key.': ' .$columns[$key]);
		if (
			!isset($columns[$key])
			|| (
				isset($columns[$key])
				&& $columns[$key] == -1
			)
			|| (
				isset($columns[$key])
				&& $columns[$key] === ''
			)
		) {
			return true;
		} else {
			return false;
		}
	}

}