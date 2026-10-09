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

	/**
	 * Valor de una columna de la fila como string, sin BOM y sin espacios ni comillas (") en los
	 * bordes.
	 *
	 * Devuelve null SOLO si la columna no está mapeada (o está marcada −1 en el mapeo), si la celda
	 * no existe (null) o si es exactamente el string vacío ''. Una celda con solo espacios, o solo
	 * comillas, NO da null: da '' (lo que queda después de limpiarla). Quien tenga que tratarla
	 * como vacía lo mira aparte (usa_columna() considera vacío al '').
	 *
	 * 🔴 NO se vuelve a comparar el VALOR de la celda con −1. La marca de "columna sin usar" vive en
	 * el MAPEO (`$columns[$key] == -1`, ver isIgnoredColumn()), y una columna marcada así ya da null
	 * acá: `isset($row[-1])` es falso. Comparar la CELDA con −1 (entró como `!= -1` y pasó a
	 * `!== -1` en 31241031, 25/11/2025) solo lograba descartar todo dato que valiera −1 entero
	 * —PhpSpreadsheet entrega `int` para un entero—: un −1 en un teléfono o un saldo no se
	 * importaba. Se sacó para todas las importaciones (misión importacion-saldo-celdas-de-texto,
	 * 9/10/2026, decisión de Lucas). La importación de artículos lee del CSV intermedio, donde todo
	 * llega como string, así que a ella esa comparación ya no la alcanzaba.
	 *
	 * @param mixed $row Fila del Excel (array o Collection).
	 * @param string $key Clave de la columna en el mapeo.
	 * @param array $columns Mapeo de columnas de la importación.
	 * @return string|null
	 */
	static function getColumnValue($row, $key, $columns) {
		if (
			isset($columns[$key])
			&& isset($row[$columns[$key]])
			&& $row[$columns[$key]] !== ''
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

	/**
	 * Valor de una columna de la fila TAL CUAL lo entrega PhpSpreadsheet (int, float, string o
	 * bool), sin pasarlo a string. Null si la columna no está mapeada (o está marcada −1 en el
	 * mapeo), si la celda no existe o si es un string vacío (después de sacar BOM y espacios).
	 *
	 * Existe para los números que tienen que distinguir una celda NUMÉRICA de una de TEXTO (el
	 * saldo de clientes y proveedores, ver leerNumeroDeCelda()): getColumnValue() convierte todo a
	 * string, y una celda numérica 1.234 (uno coma dos) se volvería el texto "1.234", que leído
	 * como texto es mil doscientos treinta y cuatro.
	 *
	 * Como en getColumnValue(), no hay centinela −1 sobre el VALOR de la celda: una celda −1 es −1.
	 *
	 * @param mixed $row Fila del Excel (array o Collection).
	 * @param string $key Clave de la columna en el mapeo.
	 * @param array $columns Mapeo de columnas de la importación.
	 * @return int|float|string|bool|null
	 */
	static function getColumnRawValue($row, $key, $columns) {
		if (!isset($columns[$key]) || !isset($row[$columns[$key]])) {
			return null;
		}

		$value = $row[$columns[$key]];

		if (is_string($value) && trim(str_replace("\xEF\xBB\xBF", '', $value)) === '') {
			return null;
		}

		return $value;
	}

	/**
	 * getColumnRawValue() probando varias claves posibles del mapeo, en orden. Devuelve el primer
	 * valor no nulo.
	 *
	 * @param mixed $row Fila del Excel.
	 * @param array $keys Claves a probar en orden (snake_case o legacy).
	 * @param array $columns Mapeo de columnas de la importación.
	 * @return int|float|string|bool|null
	 */
	static function getColumnRawValueByAliases($row, $keys, $columns) {
		foreach ($keys as $key) {
			$value = self::getColumnRawValue($row, $key, $columns);

			if (!is_null($value)) {
				return $value;
			}
		}

		return null;
	}

	/**
	 * Lee un número de una celda cruda (la de getColumnRawValue()) y dice qué encontró.
	 *
	 * Es el lector del saldo de la importación de clientes y de proveedores (misión
	 * importacion-saldo-celdas-de-texto, 9/10/2026). Antes ese saldo se leía con un `(float)` del
	 * string: "$ 52.000,50" daba 0, "52.000" daba 52 y un texto que no es un número daba 0 (y a un
	 * cliente existente le dejaba el saldo en 0 con una nota de crédito por todo lo que debía).
	 *
	 *   - null o texto vacío   -> 'vacio'.
	 *   - int o float finito   -> 'numero', TAL CUAL. 🔴 No pasa por parseNumericValue() ni por
	 *                             string: la celda numérica ya es un número, y convertida al texto
	 *                             "1.234" la regla de miles la leería como 1234.
	 *   - texto                -> se limpia (BOM, espacios, NBSP y U+202F, comillas en los bordes),
	 *                             se normaliza el signo (ver normalizarSignoYMoneda()) y se lee con
	 *                             parseNumericValue() en modo 'auto', igual que los artículos. Si no
	 *                             es un número -> 'ilegible'.
	 *   - bool, NAN/INF, array u objeto -> 'ilegible'.
	 *
	 * La interpretación del punto es siempre 'auto': clientes y proveedores no tienen el selector
	 * del paso 3 del modal ("Cómo vamos a leer los números").
	 *
	 * 🔴 El signo se arregla ACÁ y no en parseNumericValue(): en artículos un "-$ 100" en el costo
	 * tiene que seguir siendo un conflicto, no un costo negativo, y parseNumericValue() la usan
	 * igual que siempre. En un saldo, en cambio, "-$ 7.600", "+$ 7.600" o "- 500" son saldos
	 * escritos como los escribe cualquiera.
	 *
	 * Una fórmula llega como su texto ("=B2*2"): ningún importador implementa
	 * `WithCalculatedFormulas`. Queda 'ilegible', y el aviso le pide al usuario pegarla como valor.
	 *
	 * @param mixed $valor Valor crudo de la celda.
	 * @param bool $solo_pesos Con true, un texto que trae una moneda extranjera (USD, U$S, US$, en
	 *                         mayúsculas o minúsculas) queda 'ilegible' en lugar de leerse como el
	 *                         número que acompaña. Lo pide el saldo de clientes y proveedores, que
	 *                         carga la cuenta en PESOS (ver LocalImportHelper::leerSaldoDeLaFila()).
	 * @return array [
	 *   'estado' => 'vacio' | 'numero' | 'ilegible',
	 *   'valor'  => float|null   el número, solo con estado 'numero',
	 *   'texto'  => string|null  lo que traía la celda, solo con estado 'ilegible' (para el aviso),
	 * ]
	 */
	static function leerNumeroDeCelda($valor, $solo_pesos = false) {
		if (is_null($valor)) {
			return self::lecturaDeCelda('vacio');
		}

		if (is_bool($valor)) {
			// Una celda VERDADERO/FALSO no es un saldo. Se muestra como la ve el usuario en su Excel.
			return self::lecturaDeCelda('ilegible', null, $valor ? 'VERDADERO' : 'FALSO');
		}

		if (is_int($valor) || is_float($valor)) {
			if (is_float($valor) && !is_finite($valor)) {
				return self::lecturaDeCelda('ilegible', null, (string) $valor);
			}

			return self::lecturaDeCelda('numero', (float) $valor);
		}

		if (!is_string($valor)) {
			// Array u objeto: no debería llegar de una celda, pero no se lo trata como número.
			$texto = is_object($valor) && method_exists($valor, '__toString') ? (string) $valor : '';

			return self::lecturaDeCelda('ilegible', null, $texto);
		}

		// La limpieza de getColumnValue() (BOM, espacios y comillas en los bordes), más NBSP y U+202F.
		$texto = str_replace("\xEF\xBB\xBF", '', $valor);
		$texto = self::recortarEspaciosDeLosBordes($texto);
		$texto = trim($texto, '"');
		$texto = self::recortarEspaciosDeLosBordes($texto);

		if ($texto === '') {
			return self::lecturaDeCelda('vacio');
		}

		/*
		 * 🔴 Una moneda extranjera en la celda: con $solo_pesos, ilegible. parseNumericValue() saca
		 * "USD" o "U$S" del principio y lee el número que queda, así que "USD 1.500" en el saldo se
		 * cargaba como $1.500 en la cuenta en PESOS, sin aviso. Se busca en cualquier parte del
		 * texto ("1.500 USD" tampoco es un saldo en pesos).
		 */
		if ($solo_pesos && preg_match('/USD|U\$S|US\$/i', $texto) === 1) {
			return self::lecturaDeCelda('ilegible', null, $texto);
		}

		$texto_a_leer = self::normalizarSignoYMoneda($texto);

		// Doble signo o doble moneda ("$++1.500", "$$+1.500"): no se adivina, ilegible.
		if (is_null($texto_a_leer)) {
			return self::lecturaDeCelda('ilegible', null, $texto);
		}

		try {
			$numero = self::parseNumericValue($texto_a_leer, null, null, 'auto');
		} catch (\InvalidArgumentException $e) {
			return self::lecturaDeCelda('ilegible', null, $texto);
		}

		// "1e999" es numérico para PHP y da INF: tampoco es un saldo.
		if (is_null($numero) || !is_finite((float) $numero)) {
			return self::lecturaDeCelda('ilegible', null, $texto);
		}

		return self::lecturaDeCelda('numero', (float) $numero);
	}

	/**
	 * trim() que además saca el espacio de no separación (U+00A0, NBSP) y el espacio fino de no
	 * separación (U+202F) de los bordes. trim() no los ve, y un Excel armado copiando de una web o de
	 * un PDF los trae: "\u{00A0}1.500" quedaba ilegible.
	 *
	 * @param string $texto
	 * @return string
	 */
	private static function recortarEspaciosDeLosBordes($texto) {
		$recortado = preg_replace('/^[\s\x{00A0}\x{202F}]+|[\s\x{00A0}\x{202F}]+$/u', '', $texto);

		// UTF-8 inválido: preg_replace con /u devuelve null. Queda el trim() de siempre.
		if (is_null($recortado)) {
			return trim($texto);
		}

		return $recortado;
	}

	/**
	 * Deja el signo de un número escrito como texto donde parseNumericValue() lo entiende: pegado
	 * adelante, sin moneda en el medio y sin "+". Es del lector de leerNumeroDeCelda(), NO de
	 * parseNumericValue() (ver el docblock de leerNumeroDeCelda()).
	 *
	 *   "-$ 7.600", "- $7.600", "$ -7.600" -> "-7.600"   (parseNumericValue() solo saca la moneda
	 *                                                      cuando está al principio)
	 *   "+$ 7.600", "$ +7.600", "+7.600"   -> "7.600"    (la regla de miles de 'auto' no acepta el
	 *                                                      "+": "+7.600" daba 7,6)
	 *   "- 500"                            -> "-500"     (el signo con espacio)
	 *   "−7.600" (U+2212, el menos de Word y de muchos PDF) -> "-7.600"
	 *
	 * 🔴 Saca como mucho UN signo y UNA moneda. Si después queda otro signo o otra moneda —dos
	 * signos ("-$ -7.600", "$+-500", "++1.500"), un "+" en el número ("$++1.500", "$ + +7.600"),
	 * otra moneda ("$$+1.500", "$ $ +7.600")— no se adivina: devuelve null y el saldo queda
	 * ILEGIBLE. No alcanza con dejarle el texto a parseNumericValue(): lee "+1.500" como 1,5 (su
	 * regla de miles no acepta el "+"), así que "$++1.500" se cargaba como $1,50 sin aviso.
	 *
	 * Sin signo ni moneda, el texto no cambia.
	 *
	 * @param string $texto Texto ya limpio de bordes.
	 * @return string|null El texto listo para parseNumericValue(), o null si no se puede leer.
	 */
	private static function normalizarSignoYMoneda($texto) {
		// El menos tipográfico es un menos.
		$texto = str_replace("\u{2212}", '-', $texto);

		$coincide = preg_match('/^([+-]?)\s*(?:(?:USD|U\$S|US\$|\$)\s*)?([+-]?)\s*(.*)$/isu', $texto, $partes);

		// Sin coincidencia (o UTF-8 inválido): se lee el texto como vino.
		if ($coincide !== 1) {
			return $texto;
		}

		$signo_antes   = $partes[1];
		$signo_despues = $partes[2];
		$resto         = $partes[3];

		// Un signo antes y otro después de la moneda: no se adivina cuál vale.
		if ($signo_antes !== '' && $signo_despues !== '') {
			return null;
		}

		// Lo que queda tiene que ser el número solo: ni otro "+", ni un "-" adelante, ni otra moneda.
		if (preg_match('/^-|\+|\$|USD|U\$S/i', $resto) === 1) {
			return null;
		}

		$signo = $signo_antes !== '' ? $signo_antes : $signo_despues;

		// El "+" no aporta nada y parseNumericValue() lo lee mal: se saca.
		return ($signo === '-' ? '-' : '') . $resto;
	}

	/**
	 * Arma el resultado de leerNumeroDeCelda(), siempre con las tres claves.
	 *
	 * @param string $estado 'vacio' | 'numero' | 'ilegible'
	 * @param float|null $valor
	 * @param string|null $texto
	 * @return array
	 */
	private static function lecturaDeCelda($estado, $valor = null, $texto = null) {
		return [
			'estado' => $estado,
			'valor'  => $valor,
			'texto'  => $texto,
		];
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

		// Se pone en true cuando el formato es inequívocamente inválido (ej: "1.5,3").
		$formato_invalido = false;

		// Separador de miles con espacio, espacio de no separación (U+00A0), espacio fino de no
		// separación (U+202F) o apóstrofo (recto o tipográfico): "1 234,50", "1'234". Solo se acepta
		// con patrón estricto (grupos de EXACTAMENTE 3 dígitos), con o sin decimal después. Como ya
		// hay un agrupador de miles, lo que venga después (coma o punto) es siempre el decimal: no
		// hay nada que interpretar, y por eso $interpretacion_punto no aplica.
		if (preg_match('/^([+-]?\d{1,3}(?:[ \x{00A0}\x{202F}\'\x{2019}]\d{3})+)(?:[.,](\d+))?$/u', $normalized, $partes) === 1) {
			$entera = preg_replace('/[ \x{00A0}\x{202F}\'\x{2019}]/u', '', $partes[1]);
			$decimal = isset($partes[2]) ? $partes[2] : '';
			$normalized = $decimal !== '' ? $entera . '.' . $decimal : $entera;
		} elseif (strpos($normalized, ',') !== false && strpos($normalized, '.') !== false) {
			// Caso con coma y punto: el de más a la derecha es el decimal. No se toca por
			// $interpretacion_punto: acá no hay ambigüedad. El OTRO separador solo puede estar como
			// agrupador de miles bien formado (1 a 3 dígitos y después grupos de exactamente 3):
			// "1.5,3" o "1,5.3" no son un número, y antes se aceptaban en silencio como 15.3.
			$decimal_es_coma = strrpos($normalized, ',') > strrpos($normalized, '.');
			$sep_decimal = $decimal_es_coma ? ',' : '.';
			$sep_miles = $decimal_es_coma ? '.' : ',';
			$posicion = strrpos($normalized, $sep_decimal);
			$parte_entera = substr($normalized, 0, $posicion);
			$parte_decimal = substr($normalized, $posicion + 1);

			$patron_miles = '/^[+-]?\d{1,3}(' . preg_quote($sep_miles, '/') . '\d{3})+$/';
			if (preg_match($patron_miles, $parte_entera) === 1 && preg_match('/^\d*$/', $parte_decimal) === 1) {
				$normalized = str_replace($sep_miles, '', $parte_entera) . '.' . $parte_decimal;
			} else {
				$formato_invalido = true;
			}
		} elseif (substr_count($normalized, ',') >= 2 && preg_match('/^[+-]?\d{1,3}(,\d{3})+$/', $normalized) === 1) {
			// Varias comas solas y en grupos de exactamente 3 dígitos: es separador de miles
			// ("1,234,567"). Una sola coma NO entra acá: sigue siendo decimal ("1,234" -> 1.234),
			// regla fija de Lucas.
			$normalized = str_replace(',', '', $normalized);
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

		if ($formato_invalido || !is_numeric($normalized)) {
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