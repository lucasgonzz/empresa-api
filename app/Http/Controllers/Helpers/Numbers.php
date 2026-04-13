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

	/**
	 * Formatea un precio para mostrar en pantalla.
	 * 
	 * Para moneda_id = 2 (USD) siempre se muestran dos decimales,
	 * sin importar si los centavos son '00' o si el precio no tiene punto decimal.
	 * Para el resto de monedas, los decimales se omiten cuando son '00'.
	 * 
	 * @param string|float $price      Precio a formatear
	 * @param bool         $con_signo  Si es true y no hay moneda_id, antepone '$'
	 * @param int|null     $moneda_id  1 = ARS, 2 = USD
	 * @return string Precio formateado
	 */
	static function price($price, $con_signo = false, $moneda_id = null) {
		/* Verificar si el precio ya trae separador decimal */
		$pos = strpos($price, '.');
		if ($pos != false) {
			/* Extraer parte entera y centavos */
			$centavos = explode('.', $price)[1];
			$new_price = explode('.', $price)[0];
			/* Mostrar decimales si los centavos no son '00' o si es moneda USD */
			if ($centavos != '00' || $moneda_id == 2) {
				$new_price += ".$centavos";
				$result = number_format($price, 2, ',', '.');
			} else {
				$result = number_format($new_price, 0, '', '.');			
			}
		} else {
			/* Si es USD, forzar siempre dos decimales aunque el precio sea entero */
			if ($moneda_id == 2) {
				$result = number_format($price, 2, ',', '.');
			} else {
				$result = number_format($price, 0, '', '.');
			}
		}

        if ($moneda_id) {
            if ($moneda_id == 1) {
                return '$'.$result;
            } else if ($moneda_id == 2) {
                return 'USD '.$result;
            }
        }

        if ($con_signo) {
            return '$'.$result;
        }

		return $result;
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