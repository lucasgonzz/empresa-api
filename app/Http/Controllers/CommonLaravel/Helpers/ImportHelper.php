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

	/**
	 * Convierte un valor de Excel a número, tolerando formatos locales y símbolos de moneda.
	 *
	 * @param mixed $value Valor crudo de la celda.
	 * @param string|null $field_label Etiqueta del campo para mensajes de error (ej: "costo").
	 * @param int|null $row_number Número de fila del Excel para contextualizar errores.
	 * @return float|int|null Número parseado, o null si la celda estaba vacía.
	 * @throws \InvalidArgumentException Si hay valor pero no se puede interpretar como número.
	 */
	static function parseNumericValue($value, $field_label = null, $row_number = null) {
		if (is_null($value) || (is_string($value) && trim($value) === '')) {
			return null;
		}

		if (is_int($value) || is_float($value)) {
			return $value;
		}

		// Valor original tal como vino del Excel, para mostrarlo en errores al usuario.
		$original = trim((string) $value);

		// Se eliminan prefijos de moneda y espacios sobrantes (ej: "$ 37468,24").
		$normalized = preg_replace('/^(USD|U\$S|\$)\s*/iu', '', $original);
		$normalized = trim($normalized);

		// Caso con coma y punto: detectar cuál es el separador decimal.
		if (strpos($normalized, ',') !== false && strpos($normalized, '.') !== false) {
			if (strrpos($normalized, ',') > strrpos($normalized, '.')) {
				$normalized = str_replace('.', '', $normalized);
				$normalized = str_replace(',', '.', $normalized);
			} else {
				$normalized = str_replace(',', '', $normalized);
			}
		} elseif (strpos($normalized, ',') !== false) {
			$normalized = str_replace(',', '.', $normalized);
		} elseif (preg_match('/\.\d{3}$/', $normalized) === 0) {
			$normalized = str_replace('.', '', $normalized);
		}

		if (!is_numeric($normalized)) {
			$row_prefix = !is_null($row_number) ? "Fila {$row_number}: " : '';
			$field_suffix = !is_null($field_label) ? " para {$field_label}" : '';

			throw new \InvalidArgumentException(
				"{$row_prefix}El valor '{$original}' no es un número válido{$field_suffix}. Use solo números, sin símbolos de moneda."
			);
		}

		return (float) $normalized;
	}

	/**
	 * Arma el payload JSON que consume el modal global de notificaciones ante un fallo de importación.
	 *
	 * @param \Throwable $exception Excepción capturada durante la importación.
	 * @param string $title Mensaje principal del modal.
	 * @return array Payload con message, info_to_show y functions_to_execute.
	 */
	static function buildImportErrorPayload(\Throwable $exception, $title = 'Hubo un error durante la importación de Excel') {
		$detalle = self::formatImportErrorMessage($exception);

		return [
			'message' => $title,
			'info_to_show' => [
				[
					'title' => 'Detalle del error',
					'value' => $detalle,
				],
			],
			'functions_to_execute' => [
				[
					'btn_text' => 'Entendido',
					'btn_variant' => 'primary',
				],
			],
		];
	}

	/**
	 * Traduce excepciones técnicas de importación a mensajes legibles para el usuario.
	 *
	 * @param \Throwable $exception Excepción a formatear.
	 * @return string Mensaje descriptivo en español.
	 */
	static function formatImportErrorMessage(\Throwable $exception) {
		$message = $exception->getMessage();

		// Error de MySQL por decimal mal formateado (ej: "$ 37468,24" en columna cost).
		if (preg_match("/Incorrect decimal value: '([^']+)' for column '([^']+)'/", $message, $matches)) {
			$column_labels = [
				'cost' => 'costo',
				'amount' => 'cantidad',
				'received' => 'cantidad recibida',
				'price' => 'precio',
			];
			$column_label = $column_labels[$matches[2]] ?? $matches[2];

			return "El valor '{$matches[1]}' no es válido para la columna {$column_label}. Use números sin símbolos de moneda (ej: 37468.24 o 37468,24).";
		}

		// Recortar mensajes SQL muy largos dejando solo la causa principal.
		if (strpos($message, ' (SQL:') !== false) {
			$parts = explode(' (SQL:', $message);
			return trim($parts[0]);
		}

		return $message;
	}

}