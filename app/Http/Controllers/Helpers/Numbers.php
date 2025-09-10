<?php

namespace App\Http\Controllers\Helpers;
use Illuminate\Support\Facades\Log;

class Numbers {


	static function percentage($p) {
		return (float)$p / 100;
	}

    static function redondear($num) {
        return round($num, 2, PHP_ROUND_HALF_UP);
    }

	static function price($price) {
		$pos = strpos($price, '.');
		if ($pos != false) {
			$centavos = explode('.', $price)[1];
			$new_price = explode('.', $price)[0];
			if ($centavos != '00') {
				$new_price += ".$centavos";
				$resutl = number_format($price, 2, ',', '.');
			} else {
				$resutl = number_format($new_price, 0, '', '.');			
			}
		} else {
			$resutl = number_format($price, 0, '', '.');
		}
		return $resutl;
	}

	
    function normalize_number($value) {
        if (is_null($value) || $value === '') {
            return null;
        }

        // Eliminar espacios
        $value = trim($value);

        // Caso 1: si el número contiene coma y punto
        if (strpos($value, ',') !== false && strpos($value, '.') !== false) {
            // Detectar cuál es el separador decimal
            if (strrpos($value, ',') > strrpos($value, '.')) {
                // Ej: "1.000,80" → quitar puntos y cambiar coma a punto
                $value = str_replace('.', '', $value);
                $value = str_replace(',', '.', $value);
            } else {
                // Ej: "1,000.80" → quitar comas
                $value = str_replace(',', '', $value);
            }
        } 
        // Caso 2: solo coma → se asume que es decimal
        elseif (strpos($value, ',') !== false) {
            $value = str_replace(',', '.', $value);
        } 
        // Caso 3: solo punto → ya está ok, pero quitar separadores de miles si existieran
        else {
            // Eliminar cualquier separador de miles erróneo
            if (preg_match('/\.\d{3}$/', $value) === 0) {
                // Si no es decimal, quitar puntos de miles
                $value = str_replace('.', '', $value);
            }
        }

        return (float) $value;
    }
}