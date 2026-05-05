<?php

namespace App\Http\Controllers\Helpers;

use Illuminate\Support\Facades\Log;

class GlobalHelper {
	
	/**
	 * Obtiene un valor del array cuando el índice existe y el valor no representa cero.
	 *
	 * @param array $array Arreglo de entrada con datos del request o del flujo de negocio.
	 * @param string $index Clave que se desea leer dentro del arreglo.
	 * @return mixed|null Retorna el valor cuando es válido; null cuando no existe o es cero.
	 */
	static function isset_dist_0($array, $index) {
		// Se registra el índice solicitado para facilitar la trazabilidad en logs.
		// Log::info('chequeando '.$index.' en array:');
		// Log::info($array);
		// Se guarda el estado de existencia para no evaluar el acceso al índice dos veces.
		$has_index = isset($array[$index]);
		// Se guarda el valor original para aplicar validación estricta contra cero.
		$value = $has_index ? $array[$index] : null;
		// Solo se descarta el cero numérico o string "0"; cualquier otro string válido debe pasar.
		if (
			$has_index
			&& $value !== 0
			&& $value !== '0'
		) {
			// Se retorna el dato original para preservar el comportamiento previo del helper.
			// Log::info('retornando '.$index);
			return $value;
		}
		// Log::info('No esta definido el indice '.$index);
		return null;
	}
}