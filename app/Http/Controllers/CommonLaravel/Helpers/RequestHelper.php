<?php

namespace App\Http\Controllers\CommonLaravel\Helpers;
use Illuminate\Support\Facades\Log;

class RequestHelper {

	static function isset($request, $value) {
		if (isset($request->{$value})) {
			return $request->{$value};
		}
		return null;
	}

	static function isset_array($array, $value, $default_value = null) {
		if (isset($array[$value])) {
			// Log::info('Esta seteado '.$value.' con: '.$array[$value]);
			return $array[$value];
		}

		// Log::info('No esta seteado '.$value);

		if ($default_value) {
			return $default_value;
		}
		
		return null;
	}

}