<?php

namespace App\Http\Controllers\Helpers;

class GlobalHelper {
	
	static function isset_dist_0($array, $index) {
		if (
			isset($array[$index])
			&& $array[$index] != 0
		) {
			return $array[$index];
		}
		return null;
	}
}