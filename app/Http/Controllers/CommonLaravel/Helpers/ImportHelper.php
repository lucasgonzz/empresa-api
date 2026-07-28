<?php

namespace App\Http\Controllers\CommonLaravel\Helpers;
use Illuminate\Support\Facades\Log;

class ImportHelper {

	/**
	 * Normaliza el modo de interpretación del punto elegido por el usuario en el punto
	 * de entrada (controller), antes de que viaje por toda la cadena de importación.
	 * No hay que confiar en que el frontend mande siempre un valor válido: si no llega,
	 * llega vacío, o llega algo que no es uno de los tres valores reconocidos, se cae a
	 * 'auto' (el comportamiento de siempre, no cambia nada).
	 *
	 * @param mixed $valor Valor crudo recibido del request (puede ser null, string vacío, etc).
	 * @return string 'auto' | 'siempre_miles' | 'siempre_decimal'
	 */
	static function normalizarInterpretacionPunto($valor) {
		$valores_validos = ['auto', 'siempre_miles', 'siempre_decimal'];

		if (is_string($valor) && in_array($valor, $valores_validos, true)) {
			return $valor;
		}

		return 'auto';
	}

	static function getColumnValue($row, $key, $columns) {
		if (
			isset($columns[$key])
			&& isset($row[$columns[$key]])
			&& $row[$columns[$key]] !== ''
			&& $row[$columns[$key]] !== -1
		) {
			/*
			 * Antes se hacia (string) $row[...] directo. Sobre un float entero
			 * (ej: un SKU numerico leido por PhpSpreadsheet como 504346.0) eso
			 * produce "504346.0", y sobre un float grande produce notacion
			 * cientifica ("1.2345678901235E+15"). scalarToLiteralString()
			 * castea sin alterar el contenido (grupo 229, prompt 07).
			 */
			$value = self::scalarToLiteralString($row[$columns[$key]]);
			$value = str_replace("\xEF\xBB\xBF", '', (string) $value);
			$value = trim($value);
			$value = trim($value, '"');
			return trim($value);
		}
		return null;
	}

	/**
	 * Devuelve el contenido de una celda tal como se ve en el Excel, sin que
	 * PhpSpreadsheet lo convierta a int o float.
	 *
	 * Necesario para codigos: un codigo de barras de 18 digitos leido como float
	 * pierde precision antes de llegar a PHP y ya no hay forma de recuperarlo.
	 *
	 * TODO (grupo 229, prompt 07): esta funcion queda preparada para cuando se
	 * lea la celda cruda de PhpSpreadsheet (objeto Cell), pero `ArticleImport`
	 * implementa `ToCollection` de Maatwebsite: para cuando el valor llega hasta
	 * aca ya fue casteado a escalar (int/float/string) por la libreria, nunca es
	 * un objeto Cell. Conectar esto de verdad requiere un WithCustomValueBinder
	 * (o `setReadDataOnly(false)` + lectura del valor formateado) en
	 * `ArticleImport`, que se considero demasiado invasivo para el alcance de
	 * este prompt. Hoy la capa que realmente protege los codigos es la A.2
	 * (scalarToLiteralString), que cubre codigos de hasta 15 digitos (limite de
	 * precision de un float en PHP). Un codigo de mas de 15 digitos formateado
	 * como numero en el Excel seguira perdiendo precision hasta que se conecte
	 * esta capa.
	 *
	 * @param  mixed $cell
	 * @return string|null
	 */
	static function getRawCellValue($cell)
	{
		if (is_null($cell)) {
			return null;
		}

		/* Si el lector ya nos dio un objeto Cell, pedimos el valor formateado. */
		if (is_object($cell) && method_exists($cell, 'getFormattedValue')) {
			return (string) $cell->getFormattedValue();
		}

		return self::scalarToLiteralString($cell);
	}

	/**
	 * Convierte un valor escalar de celda a string SIN alterar su contenido.
	 *
	 * Reglas:
	 *   int    -> string directo
	 *   float  entero (504346.0)     -> "504346"       nunca "504346.0"
	 *   float  con decimales (2.5)   -> "2.5"          nunca notacion cientifica
	 *   bool   -> "1" / "0"
	 *   string -> se devuelve tal cual, sin trim
	 *
	 * @param  mixed $value
	 * @return string|null
	 */
	static function scalarToLiteralString($value)
	{
		if (is_null($value)) {
			return null;
		}

		if (is_bool($value)) {
			return $value ? '1' : '0';
		}

		if (is_string($value)) {
			return $value;
		}

		if (is_int($value)) {
			return (string) $value;
		}

		if (is_float($value)) {

			/* NAN e INF no son codigos. */
			if (!is_finite($value)) {
				return null;
			}

			/*
			 * Entero disfrazado de float: es el caso del 504346.0.
			 * number_format con 0 decimales no usa notacion cientifica.
			 */
			if (floor($value) == $value && abs($value) < 1.0e+15) {
				return number_format($value, 0, '.', '');
			}

			/*
			 * Decimales reales: sprintf %F evita la notacion cientifica que
			 * produciria (string) o strval().
			 */
			$formatted = sprintf('%.10F', $value);
			$formatted = rtrim($formatted, '0');
			$formatted = rtrim($formatted, '.');

			return $formatted === '' ? '0' : $formatted;
		}

		if (is_array($value) || is_object($value)) {
			return null;
		}

		return (string) $value;
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
	 * @param string $interpretacion_punto Cómo interpretar el punto cuando la celda tiene
	 *   SOLO punto (sin coma) — es el único caso ambiguo, ver más abajo. Valores válidos:
	 *   - 'auto' (default): comportamiento actual, decide con la regex de grupos de 3 dígitos
	 *     (ej: "1.234" -> 1234, pero "3330.95" -> 3330.95).
	 *   - 'siempre_miles': el punto SIEMPRE se interpreta como separador de miles, sin evaluar
	 *     la regex (ej: "3330.95" -> 333095).
	 *   - 'siempre_decimal': el punto SIEMPRE se interpreta como separador decimal, sin evaluar
	 *     la regex (ej: "2.500" -> 2.5).
	 *   Cualquier otro valor se trata como 'auto'. Este parámetro NO afecta las ramas de
	 *   "coma y punto juntos" ni "solo coma": ahí no hay ambigüedad y quedan intactas.
	 * @return float|int|null Número parseado, o null si la celda estaba vacía.
	 * @throws \InvalidArgumentException Si hay valor pero no se puede interpretar como número.
	 */
	static function parseNumericValue($value, $field_label = null, $row_number = null, $interpretacion_punto = 'auto') {
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

		// Caso con coma y punto: detectar cuál es el separador decimal. No se toca por
		// $interpretacion_punto: acá no hay ambigüedad, el de más a la derecha manda.
		if (strpos($normalized, ',') !== false && strpos($normalized, '.') !== false) {
			if (strrpos($normalized, ',') > strrpos($normalized, '.')) {
				$normalized = str_replace('.', '', $normalized);
				$normalized = str_replace(',', '.', $normalized);
			} else {
				$normalized = str_replace(',', '', $normalized);
			}
		} elseif (strpos($normalized, ',') !== false) {
			// Caso con solo coma: tampoco es ambiguo, la coma siempre es decimal.
			$normalized = str_replace(',', '.', $normalized);
		} elseif ($interpretacion_punto === 'siempre_miles') {
			// El usuario ya sabe que su proveedor usa el punto como separador de miles:
			// se sacan todos los puntos sin evaluar la regex de "auto".
			$normalized = str_replace('.', '', $normalized);
		} elseif ($interpretacion_punto === 'siempre_decimal') {
			// El usuario ya sabe que su proveedor usa el punto como separador decimal:
			// se deja el valor tal cual, el punto queda como decimal.
			// (no se hace nada, $normalized ya tiene el punto como está)
		} elseif (preg_match('/^-?\d{1,3}(\.\d{3})+$/', $normalized) === 1) {
			// 'auto' (o cualquier valor no reconocido): comportamiento actual.
			// Solo se interpreta el punto como separador de miles cuando TODO el valor
			// son grupos de exactamente 3 dígitos (ej: "1.234" o "12.345.678").
			// Cualquier otro caso se interpreta como decimal (ej: "3330.95", "2.5").
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