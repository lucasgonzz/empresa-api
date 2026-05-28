<?php

namespace App\Http\Controllers\CommonLaravel\Helpers;
use Illuminate\Support\Facades\Log;

class ImportHelper {

	static function getColumnValue($row, $key, $columns) {
		if (
			isset($columns[$key]) 
			&& isset($row[$columns[$key]])
			&& $row[$columns[$key]] !== ''
			&& $row[$columns[$key]] !== -1
		) {
			$value = (string) $row[$columns[$key]];
			$value = str_replace("\xEF\xBB\xBF", '', $value);
			$value = trim($value);
			$value = trim($value, '"');
			return trim($value);
		}
		return null;
	}

	/**
	 * Obtiene el valor de una columna probando varias claves posibles del mapeo.
	 *
	 * @param mixed $row Fila del Excel.
	 * @param array $keys Claves a probar en orden (snake_case o legacy).
	 * @param array $columns Mapeo de columnas recibido en la importación.
	 * @return string|null Valor encontrado o null si ninguna clave está mapeada.
	 */
	static function getColumnValueByAliases($row, $keys, $columns) {
		foreach ($keys as $key) {
			$value = self::getColumnValue($row, $key, $columns);

			if (!is_null($value)) {
				return $value;
			}
		}

		return null;
	}

	static function usa_columna($value) {
		return !is_null($value) && $value !== '';
	}

	static function isIgnoredColumn($key, $columns) {
		// Log::info('isIgnoredColumn para '.$key.': ' .$columns[$key]);
		if (
			!isset($columns[$key])
			|| (
				isset($columns[$key])
				&& $columns[$key] == -1
			)
			|| (
				isset($columns[$key])
				&& $columns[$key] === ''
			)
		) {
			return true;
		} else {
			return false;
		}
	}

}