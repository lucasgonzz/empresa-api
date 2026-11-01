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
		/*
		 * 🔴 PHP 8: `number_format()` y la suma de strings con un valor no numérico («$88.782,13», un
		 * array, un objeto) son TypeError, no un warning como en el 7.4 del shared. Se lleva a número
		 * antes de formatear; lo que no es un número se muestra como 0 en vez de tumbar la pantalla.
		 */
		$price = self::to_displayable_number($price);

		/* Verificar si el precio ya trae separador decimal */
		$pos = strpos($price, '.');
		if ($pos != false) {
			/* Extraer parte entera y centavos */
			$centavos = explode('.', $price)[1];
			$new_price = explode('.', $price)[0];
			/* Mostrar decimales si los centavos no son '00' o si es moneda USD */
			if ($centavos != '00' || $moneda_id == 2) {
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

	/**
	 * Lleva lo que llegue a un string numérico estándar (punto decimal, sin separadores de miles) que
	 * `number_format()` y las cuentas aceptan en PHP 8. Un valor que ya es numérico se devuelve igual
	 * (como string) para no cambiar el formato que `price()` espera; lo que no se puede leer, '0'.
	 *
	 * @param mixed $value
	 * @return string
	 */
	private static function to_displayable_number($value) {

		if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
			return (string) $value;
		}

		$parsed = self::parse_input_decimal($value);

		return is_null($parsed) ? '0' : (string) $parsed;
	}

	/**
	 * Lee un número que escribió una persona, con el formato de la Argentina o el estándar:
	 * «$88.782,13», «88.782,13», «88782.13», «1,000.80», «$ 1.234», «-5,5», «12 %». Devuelve float, o
	 * null si no hay ni un dígito (null, '', 'abc', array).
	 *
	 * Se usa donde el valor viene del formulario y va a una columna decimal o a una cuenta: en PHP 8
	 * (VPS) un string no numérico en una suma es TypeError y en el shared MySQL lo rechaza con
	 * «Incorrect decimal value».
	 *
	 * Reglas, en orden:
	 *  - Ya es numérico → tal cual (no se reinterpreta: «1.234» sigue siendo 1,234).
	 *  - Se sacan símbolos y espacios; queda un solo signo menos al principio y dígitos, puntos y comas.
	 *  - Coma y punto juntos: el que está más a la derecha es el decimal.
	 *  - Solo coma: decimal («12,5»). Si hay varias comas, son miles.
	 *  - Solo puntos: varios puntos → miles, salvo que el último grupo no tenga 3 dígitos («88.782.13» es
	 *    88782,13). Un solo punto con exactamente 3 dígitos detrás se lee como miles («$88.782» es
	 *    88782, el formato con que el sistema muestra los precios).
	 *
	 * @param mixed $value
	 * @return float|null
	 */
	static function parse_input_decimal($value) {

		if (is_int($value) || is_float($value)) {
			return (float) $value;
		}

		if (!is_string($value)) {
			return null;
		}

		if (is_numeric($value)) {
			return (float) $value;
		}

		$negative = (bool) preg_match('/^[^0-9]*-/u', $value);

		$limpio = preg_replace('/[^0-9.,]/u', '', $value);

		if ($limpio === null || !preg_match('/[0-9]/', $limpio)) {
			return null;
		}

		$ultima_coma  = strrpos($limpio, ',');
		$ultimo_punto = strrpos($limpio, '.');

		if ($ultima_coma !== false && $ultimo_punto !== false) {

			if ($ultima_coma > $ultimo_punto) {
				$limpio = str_replace('.', '', $limpio);
				$limpio = str_replace(',', '.', $limpio);
			} else {
				$limpio = str_replace(',', '', $limpio);
			}

		} else if ($ultima_coma !== false) {

			if (substr_count($limpio, ',') > 1) {
				$limpio = str_replace(',', '', $limpio);
			} else {
				$limpio = str_replace(',', '.', $limpio);
			}

		} else if ($ultimo_punto !== false) {

			$grupos = explode('.', $limpio);
			$ultimo = end($grupos);

			if (count($grupos) > 2) {
				$todos_de_tres = true;
				foreach (array_slice($grupos, 1) as $grupo) {
					if (strlen($grupo) !== 3) {
						$todos_de_tres = false;
					}
				}
				if ($todos_de_tres) {
					$limpio = str_replace('.', '', $limpio);
				} else {
					$limpio = str_replace('.', '', substr($limpio, 0, $ultimo_punto)).substr($limpio, $ultimo_punto);
				}
			} else if (strlen($ultimo) === 3) {
				$limpio = str_replace('.', '', $limpio);
			}
		}

		if (!is_numeric($limpio)) {
			return null;
		}

		$numero = (float) $limpio;

		return $negative ? -$numero : $numero;
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