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

}