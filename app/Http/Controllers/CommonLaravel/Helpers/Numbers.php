<?php

namespace App\Http\Controllers\CommonLaravel\Helpers;
use Illuminate\Support\Facades\Log;

class Numbers {


	static function percentage($p) {
		return (float)$p / 100;
	}

    static function redondear($num) {
        return round($num, 2, PHP_ROUND_HALF_UP);
    }

	static function price($price) {
		/* Mismo formato de siempre; la versión de App tolera lo que no es un número (PHP 8: TypeError). */
		return \App\Http\Controllers\Helpers\Numbers::price($price);
	}
}