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

		if (is_int($value) || is_float($value)) {
			return $value;
		}

		if (is_bool($value)) {
			return (int) $value;
		}

		if (is_string($value) && is_numeric($value)) {
			return $value;
		}

		$parsed = self::parse_input_decimal($value);

		return is_null($parsed) ? 0 : $parsed;
	}

	/**
	 * Lee un número que escribió una persona, con el formato de la Argentina o el estándar:
	 * «$88.782,13», «88.782,13», «88782.13», «1,000.80», «$ 1.234», «-5,5», «12 %». Devuelve float, o
	 * null si no se puede leer con certeza (null, '', 'abc', un array, letras mezcladas con dígitos,
	 * separadores que no cierran como miles, un signo menos que no está al principio).
	 *
	 * Se usa donde el valor viene del formulario y va a una columna decimal o a una cuenta: en PHP 8
	 * (VPS) un string no numérico en una suma es TypeError y en el shared MySQL lo rechaza con
	 * «Incorrect decimal value». Devuelve lo mismo en PHP 7.4 y 8 (se recorta el texto antes de mirar
	 * si es numérico, porque `is_numeric()` acepta espacios finales solo desde PHP 8).
	 *
	 * Reglas, en orden:
	 *  - Ya es numérico → tal cual (no se reinterpreta: «1.234» sigue siendo 1,234).
	 *  - Solo se aceptan dígitos, puntos, comas, `$`, `%`, espacios (incluido el duro) y el signo menos
	 *    ADELANTE. Cualquier otra cosa (letras, `(5)`, `5-`, notación científica con coma) es null: es
	 *    plata, y se rechaza antes que inventar un valor.
	 *  - Coma y punto juntos: el que está más a la derecha es el decimal y el otro tiene que estar
	 *    agrupando de a tres.
	 *  - Solo coma: una sola es decimal («12,5»); varias son miles y tienen que agrupar de a tres.
	 *  - Solo puntos: varios → miles (grupos de tres), salvo el último que puede ser el decimal
	 *    («88.782.13» es 88782,13). Un solo punto con exactamente tres dígitos detrás se lee como miles
	 *    («$88.782» es 88782, el formato con que el sistema muestra los precios).
	 *
	 * @param mixed $value
	 * @return float|null
	 */
	static function parse_input_decimal($value) {

		if (is_int($value)) {
			return (float) $value;
		}

		if (is_float($value)) {
			return is_finite($value) ? $value : null;
		}

		if (!is_string($value)) {
			return null;
		}

		/* Espacios normales y duros (U+00A0) de los extremos. */
		$texto = preg_replace('/^[\s\x{00A0}]+|[\s\x{00A0}]+$/u', '', $value);

		if ($texto === null || $texto === '') {
			return null;
		}

		if (is_numeric($texto)) {
			$numero = (float) $texto;
			return is_finite($numero) ? $numero : null;
		}

		/* Sin símbolos de moneda, porcentaje ni espacios sueltos; el signo menos solo adelante. */
		$limpio = preg_replace('/[$%\s\x{00A0}]+/u', '', $texto);

		if ($limpio === null) {
			return null;
		}

		$negativo = false;

		if (preg_match('/^[-\x{2212}]/u', $limpio)) {
			$negativo = true;
			$limpio = preg_replace('/^[-\x{2212}]/u', '', $limpio);
		}

		if (!preg_match('/^[0-9.,]+$/', $limpio) || !preg_match('/[0-9]/', $limpio)) {
			return null;
		}

		$ultima_coma  = strrpos($limpio, ',');
		$ultimo_punto = strrpos($limpio, '.');

		if ($ultima_coma !== false && $ultimo_punto !== false) {

			/* El separador más a la derecha es el decimal, y tiene que aparecer una sola vez. */
			$decimal = $ultima_coma > $ultimo_punto ? ',' : '.';
			$miles   = $decimal === ',' ? '.' : ',';

			if (substr_count($limpio, $decimal) !== 1) {
				return null;
			}

			$partes = explode($decimal, $limpio);

			if (!self::agrupa_de_a_tres($partes[0], $miles)) {
				return null;
			}

			$limpio = str_replace($miles, '', $partes[0]).'.'.$partes[1];

		} else if ($ultima_coma !== false) {

			if (substr_count($limpio, ',') === 1) {
				$limpio = str_replace(',', '.', $limpio);
			} else if (self::agrupa_de_a_tres($limpio, ',')) {
				$limpio = str_replace(',', '', $limpio);
			} else {
				return null;
			}

		} else if ($ultimo_punto !== false) {

			$grupos = explode('.', $limpio);
			$ultimo = end($grupos);

			if (count($grupos) === 2) {
				if (strlen($ultimo) === 3 && $grupos[0] !== '' && $grupos[0] !== '0') {
					$limpio = $grupos[0].$ultimo;
				}
			} else if (self::agrupa_de_a_tres($limpio, '.')) {
				$limpio = str_replace('.', '', $limpio);
			} else if (self::agrupa_de_a_tres(implode('.', array_slice($grupos, 0, -1)), '.')) {
				$limpio = implode('', array_slice($grupos, 0, -1)).'.'.$ultimo;
			} else {
				return null;
			}
		}

		if (!is_numeric($limpio)) {
			return null;
		}

		$numero = (float) $limpio;

		if (!is_finite($numero)) {
			return null;
		}

		return $negativo ? -$numero : $numero;
	}

	/** «1.234.567» con separador «.»: el primer grupo de 1 a 3 dígitos y el resto de exactamente 3. */
	private static function agrupa_de_a_tres($texto, $separador) {

		$grupos = explode($separador, $texto);

		if (strlen($grupos[0]) < 1 || strlen($grupos[0]) > 3) {
			return false;
		}

		foreach (array_slice($grupos, 1) as $grupo) {
			if (strlen($grupo) !== 3) {
				return false;
			}
		}

		return true;
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