<?php

namespace App\Http\Controllers\CommonLaravel\Helpers;
use Illuminate\Support\Facades\Log;

class ImportHelper {

	static function getColumnValue($row, $key, $columns) {
		if (isset($columns[$key]) && isset($row[$columns[$key]])) {
			return $row[$columns[$key]];
		}
		return null;
	}

	static function isIgnoredColumn($key, $columns) {
		if (!isset($columns[$key])) {
			return true;
		} else {
			return false;
		}
	}

}