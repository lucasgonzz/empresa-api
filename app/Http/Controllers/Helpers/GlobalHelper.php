<?php

namespace App\Http\Controllers\Helpers;

use Illuminate\Support\Facades\Log;

class GlobalHelper {
	
	static function isset_dist_0($array, $index) {
		if (
			isset($array[$index])
			&& $array[$index] != 0
		) {
			return $array[$index];
		}
		Log::info('No esta definido el indice '.$index);
		return null;
	}
}