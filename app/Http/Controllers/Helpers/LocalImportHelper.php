<?php

namespace App\Http\Controllers\Helpers;
use App\Http\Controllers\CommonLaravel\Helpers\ImportHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Helpers\CurrentAcountHelper;
use App\Http\Controllers\Helpers\CreditAccountHelper;
use App\Http\Controllers\Helpers\category\SetPriceTypesHelper;
use App\Models\Brand;
use App\Models\Category;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\Iva;
use App\Models\SubCategory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LocalImportHelper {

	/**
	 * Tope de filas que nombra el aviso de saldos ilegibles (bloque_de_saldos_ilegibles()). Del
	 * resto se dice cuántas son. El evento de la notificación de fin viaja por Pusher, que corta en
	 * 10 KB: sin tope, un archivo con cientos de saldos ilegibles perdería la notificación ENTERA,
	 * botón incluido. Mismo criterio que ProviderImport::MAXIMO_DE_PROVEEDORES_EN_EL_AVISO.
	 *
	 * Es el tope del caso típico: el que de verdad manda es PRESUPUESTO_DE_BYTES_DE_LOS_AVISOS, que
	 * lo achica si los avisos no entran (ver avisos_dentro_del_presupuesto()). Es 20 y no 30 por una
	 * medición: en proveedores este bloque convive con el de "Saldos del Excel que no se cargaron"
	 * (hasta 50 nombres), y con los dos al tope y nombres y textos largos el evento pesó 10.056 bytes
	 * con 30 filas, 9.401 con 25 y 8.747 con 20 (9/10/2026).
	 *
	 * @var int
	 */
	const MAXIMO_DE_FILAS_EN_EL_AVISO_DE_SALDOS_ILEGIBLES = 20;

	/**
	 * Bytes que puede ocupar `info_to_show` (los bloques de avisos), medido como lo manda Pusher:
	 * `strlen(json_encode(...))`, con cada caracter no ASCII escapado a `\uXXXX` (seis bytes).
	 *
	 * 🔴 Por qué 8000: Pusher corta el evento en 10 KB (10.240 bytes) y, si se pasa, se pierde la
	 * notificación ENTERA de fin de importación, botón incluido. El resto de los datos de la
	 * notificación (mensaje, botón, owner_id...) ronda los 450 bytes y Laravel le suma unos 100 al
	 * emitirla; con 8000 para los avisos el evento queda por debajo de los 9000 que mide el test, con
	 * margen hasta el límite. Los topes fijos solos no alcanzaban: con datos reales (nombres de más
	 * de 60 caracteres con tildes y eñes, montos de cientos de millones) el bloque de los "no se
	 * cargaron" de proveedores, con sus 50 nombres, ya pasaba los 9 KB (9/10/2026).
	 *
	 * @var int
	 */
	const PRESUPUESTO_DE_BYTES_DE_LOS_AVISOS = 8000;

	/**
	 * Largo máximo, en caracteres, del nombre y del texto de la celda en cada párrafo de los avisos
	 * de saldo: el nombre en los dos (el de saldos ilegibles y el de "no se cargaron" de
	 * ProviderImport), el texto de la celda en el de ilegibles. Lo que pasa se corta con "…" (ver
	 * recortar_para_el_aviso()). También es por el límite de Pusher.
	 *
	 * @var int
	 */
	const LARGO_MAXIMO_DEL_NOMBRE_EN_EL_AVISO = 60;
	const LARGO_MAXIMO_DEL_TEXTO_EN_EL_AVISO = 40;

	/**
	 * Lee el saldo del Excel de una fila (columna "saldo actual") con ImportHelper::leerNumeroDeCelda().
	 *
	 * Es la lectura que comparten clientes (procesarSaldoImportacion()) y proveedores
	 * (setSaldoInicial()). 🔴 Se lee la celda CRUDA (getColumnRawValueByAliases()) y no el string de
	 * getColumnValueByAliases(): una celda NUMÉRICA tiene que entrar tal cual, y pasada a string
	 * ("1.234") la lectura de texto la tomaría como miles. Antes se hacía `(float)` del string:
	 * "$ 52.000,50" daba 0 y "52.000" daba 52 (misión importacion-saldo-celdas-de-texto, 9/10/2026).
	 *
	 * 🔴 Se lee "solo pesos": un saldo escrito en dólares ("USD 1.500", "-USD 100", "U$S 100",
	 * "US$ 100") queda ILEGIBLE y se avisa. Esta columna carga la cuenta corriente en PESOS (moneda
	 * 1, ver get_credit_account_pesos()); leído como el número que acompaña, "USD 1.500" quedaba
	 * como una deuda de $1.500 sin que nadie se enterara. El rechazo es propio del saldo: en
	 * artículos parseNumericValue() sigue ignorando la moneda, como siempre.
	 *
	 * ⚠️ Límite: el rechazo mira el TEXTO de la celda. Una celda NUMÉRICA con formato de dólares
	 * (1500 con formato `[$USD] #,##0.00`) se lee como el número 1500, porque la importación no ve el
	 * formato: Maatwebsite lee con `read_only` y sin `WithFormatData`, y entrega el entero pelado.
	 *
	 * @param mixed $row Fila del Excel.
	 * @param array $columns Mapeo de columnas de la importación.
	 * @return array El resultado de ImportHelper::leerNumeroDeCelda(): ['estado', 'valor', 'texto'].
	 */
	static function leerSaldoDeLaFila($row, $columns) {
		$celda = ImportHelper::getColumnRawValueByAliases($row, ['saldo_actual', 'saldo actual'], $columns);

		return ImportHelper::leerNumeroDeCelda($celda, true);
	}

	/**
	 * Procesa la columna saldo del Excel según si el cliente/proveedor es nuevo o existente.
	 *
	 * Hoy lo usa solo `ClientImport`. Al nuevo le carga el saldo inicial y al EXISTENTE le ajusta
	 * el saldo con una nota de crédito o de débito. La importación de proveedores usa
	 * setSaldoInicial(), que no ajusta nunca: ver su docblock.
	 *
	 * El saldo se lee con leerSaldoDeLaFila(): una celda NUMÉRICA entra tal cual y una de TEXTO se
	 * lee como los artículos ("$ 52.000,50", "52.000", "-$ 7.600"). Antes era un `(float)` del
	 * string, que daba 0 o 52 en esos casos.
	 *
	 * 🔴 Un saldo ILEGIBLE ("s/d", "-", una fórmula) no toca NADA: ni crea la cuenta, ni carga un
	 * saldo inicial, ni ajusta. No se lo trata como 0 a propósito: a un cliente existente un 0 le
	 * cargaba una nota de crédito por todo lo que debía (decisión de Lucas, 9/10/2026). La fila se
	 * importa igual con el resto de sus datos (eso ya pasó antes de llamar acá) y ClientImport lo
	 * avisa al final, con el texto que devuelve $texto_ilegible.
	 *
	 * @param mixed $row Fila del Excel.
	 * @param array $columns Mapeo de columnas de la importación.
	 * @param string $model_name Nombre del modelo (`client` o `provider`).
	 * @param mixed $model Instancia persistida del cliente/proveedor.
	 * @param bool $is_existing_model Indica si el registro ya existía antes de importar.
	 * @param string|null $texto_ilegible SALIDA, opcional: con el estado 'ilegible', lo que traía la
	 *                                    celda, para el aviso.
	 * @return string 'sin_saldo' -> la fila no trae saldo o no hay modelo: nada que hacer.
	 *                'ilegible'  -> la celda trae algo que no es un número: no se tocó nada.
	 *                'procesado' -> se cargó el saldo inicial o se ajustó el saldo del existente.
	 */
	static function procesarSaldoImportacion($row, $columns, $model_name, $model, $is_existing_model = false, &$texto_ilegible = null) {
		if (is_null($model)) {
			return 'sin_saldo';
		}

		$lectura = self::leerSaldoDeLaFila($row, $columns);

		if ($lectura['estado'] == 'vacio') {
			return 'sin_saldo';
		}

		if ($lectura['estado'] == 'ilegible') {
			$texto_ilegible = $lectura['texto'];

			return 'ilegible';
		}

		$credit_account = self::get_credit_account_pesos($model_name, $model->id);

		if (is_null($credit_account)) {
			CreditAccountHelper::crear_credit_accounts($model_name, $model->id);
			$credit_account = self::get_credit_account_pesos($model_name, $model->id);
		}

		if (is_null($credit_account)) {
			// No hay dónde cargarlo. No debería pasar: crear_credit_accounts() la acaba de crear.
			return 'sin_saldo';
		}

		$saldo_importado = $lectura['valor'];

		if ($is_existing_model) {
			self::ajustarSaldoPorImportacion($saldo_importado, $credit_account, $model_name, $model->id);
			return 'procesado';
		}

		self::crearSaldoInicialPorImportacion($saldo_importado, $credit_account, $model_name, $model);

		return 'procesado';
	}

	/**
	 * Columnas de una lista de clientes o proveedores que dicen QUIÉN es el contacto: si una de estas trae
	 * dato, la fila es un contacto, se llame como se llame.
	 */
	const COLUMNAS_QUE_IDENTIFICAN_UN_CONTACTO = ['numero', 'telefono', 'direccion', 'email', 'razon_social', 'cuit', 'cuil', 'dni', 'localidad', 'provincia'];

	/**
	 * ¿La fila de una lista de clientes o proveedores no es un contacto sino el total, un subtotal o un título
	 * decorado de la planilla? Devuelve el texto de la fila para el aviso, o null si es un contacto (misión
	 * prueba-e2e-campana-imperio, ronda 6, E8.1: «deudores.xlsx» de la prueba creaba un cliente «TOTAL» con
	 * $ 555.700,50 de saldo, la suma de la columna «Debe»).
	 *
	 * Es la misma clase de fila que la carga asistida de artículos de la prueba deja afuera
	 * (PlanillaDePrueba::motivo_para_saltear()), con el mismo criterio para el nombre:
	 *  - el nombre empieza con la PALABRA COMPLETA total, totales, subtotal o suma(s), sin números y con dos
	 *    palabras más como mucho (PlanillaDePrueba::es_rotulo_de_total(): «TOTAL», «Total general:», «Subtotal
	 *    zona norte»; NO «Totalgas SRL»), o el pie que cuenta («Total: 21 clientes», E8.2; NO «Total 2000 SA»), o es un título decorado («--- MAYORISTAS ---»); y
	 *  - ninguna columna que identifica a un contacto trae dato (número, teléfono, dirección, email, razón
	 *    social, CUIT, CUIL, DNI, localidad, provincia), y las demás columnas mapeadas traen solo números (el
	 *    saldo sumado) o nada.
	 * Un cliente real que se llame «Total Gas» y venga sin ningún dato más que el saldo se dejaría afuera: por eso
	 * la fila sale NOMBRADA en el aviso de la importación y el dueño puede cargarlo a mano.
	 *
	 * Solo lo llaman ClientImport y ProviderImport cuando el negocio está en la prueba gratuita (ver
	 * descartando_filas_que_no_son_contactos()); a un negocio que paga le sigue entrando todo como siempre.
	 *
	 * @param  array|\ArrayAccess $row
	 * @param  array              $columns  Clave de columna => índice
	 * @return string|null
	 */
	static function fila_que_no_es_un_contacto($row, $columns) {
		$motivo = self::motivo_de_fila_que_no_es_un_contacto($row, $columns);

		return is_null($motivo) ? null : $motivo[1];
	}

	/**
	 * Cuántos contactos con algún dato además del nombre (teléfono, CUIT, saldo…) tiene que traer la lista, como
	 * mínimo, para juzgar un título de sección con señal FUERTE (ver motivo_de_fila_que_no_es_un_contacto()).
	 */
	const CONTACTOS_CON_DATOS_PARA_TITULOS_CON_SENAL = 3;

	/**
	 * Para la señal DÉBIL (todo en mayúsculas): cuántos contactos con datos, como mínimo, y además 3 de cada 4.
	 */
	const CONTACTOS_CON_DATOS_PARA_TITULOS_EN_MAYUSCULAS = 5;

	/*
	 * ═══════════════════════════════════════════════════════════════════════════════════════════════════════════════
	 * TABLA DE DECISIÓN — filas de una lista de clientes o proveedores de la PRUEBA (ronda 8, sexta pasada)
	 * CRITERIO: una fila queda afuera solo con una señal de ALTA precisión. Ante la duda ENTRA, y el aviso lo dice
	 * («N quedaron solo con el nombre…»). Cuando lo que está en duda es PLATA (un saldo), no se adivina: un saldo que no
	 * se lee frena la carga, y un total no se carga como cliente. Todo lo que queda afuera sale nombrado. Fuera de la
	 * prueba no corre nada.
	 * ───────────────────────────────────────────────────────────────────────────────────────────────────────────────
	 *  Señal                         | Condición                                              | Afuera          | Entra
	 * ───────────────────────────────┼────────────────────────────────────────────────────────┼─────────────────┼──────────────────
	 *  Total / subtotal              | empieza con total/subtotal/sub total/totales/suma/«son | «TOTAL», «Total | «Total Hogar SRL»
	 *                                | N», CUALQUIER cantidad de palabras, sin datos de        | Villa del Parque»,| con o sin teléfono
	 *                                | contacto (solo importes); o sin nombre con el rótulo en | «| | TOTAL | 900»| (razón social o
	 *                                | otra columna. Nunca razón social ni genérico            |                 | genérico)
	 *  Suma del grupo (sexta)        | sin datos de contacto, saldo = suma de su grupo o de la | «Zona sur | 4200»| «Sandra Ojeda |
	 *                                | lista (±0,02, ≥2 filas), en posición de total; o, sin   | seguida de otro | 4200» que no es
	 *                                | posición, si sacándola cierra con el total general      | grupo           | la suma
	 *  Título repetido en su total   | el texto es lo que sigue a la palabra de total en un    | «Barrio Norte» ↔|
	 *  (sexta)                       | total de la misma lista                                 | «Total Barrio Norte»|
	 *  Título decorado               | «--- X ---», «*** X ***»                                | «--- MAYORISTAS ---»|
	 *  Cabecera repetida             | dos o más celdas iguales a los títulos                  | «Cliente | CUIT» |
	 *  Pie                           | debajo del total que tiene arriba el 80 % de los        | la nota de abajo| clientes debajo de
	 *                                | contactos con datos (un total arriba no abre nada)      | del TOTAL final | un TOTAL de arriba
	 *  Nota con forma de oración     | SOLO arriba del primer contacto con datos o debajo del  | «Fulana dejó    | «Pérez, el de la
	 *                                | último (tiene_forma_de_oracion)                         | plata…» al pie  | esquina» en el medio
	 *  Anotación (en cualquier lado) | PALABRAS_DE_ANOTACION + «:» o minúscula; una marca      | «ojo: …»,       | «Ver Pérez»,
	 *                                | («(…)», «*», «//», «->», «NB») solo con anotación u     | «(ver con Hugo)»| «* Carla», «NB Carla»
	 *                                | oración adentro                                         |                 | «Falta Gómez»
	 *  Señal fuerte (de siempre)     | termina en «:» o palabra de sección (≤3 palabras)       | «Zona norte:»   | «Zona Sur Materiales»
	 *  Señal débil (de siempre)      | todo en mayúsculas en una lista que no escribe así      | «DEUDORES»      | «YPF»
	 *  Grupo (solo nombre)           | ≥5 contactos con datos, 2 por cada fila de solo nombre, | «Mayoristas»,   | «Gómez», «Kiosco»,
	 *   · vocabulario                | PALABRAS_DE_GRUPO + lo que sigue en minúscula (en lista | «Del barrio»,   | «Del Valle»,
	 *   · preposición                | en minúscula: solo una palabra sola)                    | «Clientes del   | «de la torre» en
	 *   · formato                    | negrita exclusiva o combinada de 3+ columnas, CON        | taller», «Fiado»| lista minúscula;
	 *                                | estructura de grupos (formato_con_estructura)           | en negrita      | 8 en negrita
	 *                                |                                                         | encabezando     | dispersos
	 *  Sin ninguna de esas señales   | —                                                       | —               | entra, y si trae
	 *                                |                                                         |                 | solo el nombre, se
	 *                                |                                                         |                 | dice en el aviso
	 * ═══════════════════════════════════════════════════════════════════════════════════════════════════════════════
	 */
	/**
	 * Las filas de una lista de clientes o proveedores que no son contactos: número de fila => [motivo, texto]
	 * (misión prueba-e2e-campana-imperio, E8.1 a E8.3). Es lo que usan ClientImport y ProviderImport en la prueba
	 * y lo que mide ImportacionDePruebaFilasQueNoSonDatosGenerativoTest: una sola función para los dos.
	 *
	 * ClientImport y ProviderImport son ToCollection sin WithChunkReading: reciben la hoja completa, así que esto
	 * son dos pasadas en memoria, sin consultas.
	 *
	 * @param  \Illuminate\Support\Collection|array $rows
	 * @param  array                                $columns
	 * @param  int                                  $start_row   1-based
	 * @param  int                                  $finish_row  1-based, incluida
	 * @return array<int, array>
	 */
	static function filas_que_no_son_contactos_de($rows, $columns, $start_row, $finish_row, array $formato = []) {
		// $formato (ronda 8, A1, tercera pasada): número de fila => la celda del nombre está destacada (negrita, relleno o
		// combinada). Lo arma quien puede leer el archivo con estilos; vacío (ClientImport, CSV) = sin señal, como hasta hoy.
		$contexto = self::contexto_de_contactos($rows, $columns, $start_row, $finish_row, $formato);
		$descartes = [];
		$numero = 0;
		foreach ($rows as $row) {
			$numero++;
			if ($numero < (int) $start_row || $numero > (int) $finish_row) {
				continue;
			}
			// Quinta pasada (A3): el pie lo abre solo el total que tiene arriba la mayoría de los contactos con datos.
			$despues_del_total = !is_null($contexto['pie']) && $numero > (int) $contexto['pie'];
			$motivo = self::motivo_de_fila_que_no_es_un_contacto($row, $columns, $contexto, $numero, $despues_del_total);
			// El total sin nombre (el rótulo en otra columna): se nombra entre las que quedan afuera y abre el pie (A4, A5).
			if (is_null($motivo) && isset($contexto['con_datos'])) {
				$rotulo = self::total_sin_nombre($row, $columns);
				$motivo = is_null($rotulo) ? null : ['total', $rotulo];
			}
			if (!is_null($motivo)) {
				$descartes[$numero] = $motivo;
			}
		}

		return isset($contexto['con_datos']) ? self::subtotales_por_suma($rows, $columns, $start_row, $finish_row, $contexto, $descartes) : $descartes;
	}

	/** Tolerancia de las sumas de saldos, en pesos (sexta pasada). */
	const TOLERANCIA_DE_LOS_SALDOS = 0.02;

	/**
	 * Los subtotales que se delatan por la suma (sexta pasada, 3): cuando lo que está en duda es plata, no se adivina.
	 *  (a) Una fila con nombre, SIN datos de contacto (teléfono, CUIT, dirección…: solo el saldo), cuyo saldo es igual
	 *      (±TOLERANCIA_DE_LOS_SALDOS) a la suma de su grupo (desde el título o el total anterior) o de toda la lista de
	 *      arriba, con dos filas sumadas o más, y en posición de total (seguida de una fila vacía, de algo que no es un
	 *      contacto con datos o de ningún contacto más).
	 *  (b) El control de coherencia: si la lista trae un total general (el del pie) y la suma de los saldos que se van a
	 *      cargar no cierra con él, pero SÍ cerraría sacando las filas sin datos de contacto cuyo saldo es la suma de su
	 *      grupo, esas filas son subtotales.
	 * Nunca una razón social ni un genérico.
	 *
	 * @return array  los descartes, con los subtotales agregados
	 */
	private static function subtotales_por_suma($rows, $columns, $start_row, $finish_row, array $contexto, array $descartes) {
		if (!isset($columns['saldo_actual'])) {
			return $descartes;
		}
		$lista = is_object($rows) && method_exists($rows, 'values') ? $rows->values()->all() : array_values((array) $rows);
		$tipos = [];
		$saldos = [];
		$solo_saldo = [];
		foreach ($lista as $indice => $row) {
			$numero = $indice + 1;
			if ($numero < (int) $start_row || $numero > (int) $finish_row) {
				continue;
			}
			$nombre = ImportHelper::getColumnValue($row, 'nombre', $columns);
			$otros = self::otros_datos_del_contacto($row, $columns);
			if ((is_null($nombre) || $nombre === '') && $otros === []) {
				$tipos[$numero] = 'vacia';
				continue;
			}
			if (isset($descartes[$numero]) || is_null($nombre) || $nombre === '') {
				$tipos[$numero] = 'otro';
				continue;
			}
			$lectura = self::leerSaldoDeLaFila($row, $columns);
			$saldos[$numero] = $lectura['estado'] === 'numero' ? (float) $lectura['valor'] : null;
			$identifica = array_intersect_key($otros, array_flip(self::COLUMNAS_QUE_IDENTIFICAN_UN_CONTACTO)) !== [];
			$tipos[$numero] = $otros === [] ? 'solo_nombre' : 'dato';
			$solo_saldo[$numero] = !$identifica && !is_null($saldos[$numero]) && !self::nunca_es_titulo($nombre)
				&& array_keys($otros) === ['saldo_actual'];
		}
		$candidatos = [];
		$grupo = 0.0;
		$en_el_grupo = 0;
		$todo = 0.0;
		$en_la_lista = 0;
		$numeros = array_keys($tipos);
		foreach ($numeros as $posicion => $numero) {
			if ($tipos[$numero] !== 'dato' && $tipos[$numero] !== 'solo_nombre') {
				if ($tipos[$numero] === 'otro') {
					$grupo = 0.0;
					$en_el_grupo = 0;
				}
				continue;
			}
			$saldo = $saldos[$numero];
			if (!empty($solo_saldo[$numero]) && abs($saldo) > 0.005
				&& (($en_el_grupo >= 2 && abs($saldo - $grupo) <= self::TOLERANCIA_DE_LOS_SALDOS)
					|| ($en_la_lista >= 2 && abs($saldo - $todo) <= self::TOLERANCIA_DE_LOS_SALDOS))) {
				$candidatos[$numero] = true;
				$grupo = 0.0;
				$en_el_grupo = 0;
				continue;
			}
			if (!is_null($saldo)) {
				$grupo += $saldo;
				$todo += $saldo;
				$en_el_grupo++;
				$en_la_lista++;
			}
		}
		// (a) En posición de total.
		foreach (array_keys($candidatos) as $numero) {
			$posicion = array_search($numero, $numeros, true);
			$siguiente = null;
			$hay_datos_despues = false;
			for ($i = $posicion + 1; $i < count($numeros); $i++) {
				$tipo = $tipos[$numeros[$i]];
				if (is_null($siguiente)) {
					$siguiente = $tipo;
				}
				if ($tipo === 'dato') {
					$hay_datos_despues = true;
					break;
				}
			}
			if ($siguiente !== 'dato' || !$hay_datos_despues) {
				$descartes[$numero] = ['total', (string) ImportHelper::getColumnValue($lista[$numero - 1], 'nombre', $columns)];
				unset($candidatos[$numero]);
			}
		}
		// (b) El control de coherencia con el total general.
		$pie = $contexto['pie'] ?? null;
		if ($candidatos !== [] && !is_null($pie) && isset($lista[$pie - 1])) {
			$lectura = self::leerSaldoDeLaFila($lista[$pie - 1], $columns);
			if ($lectura['estado'] === 'numero') {
				$total = (float) $lectura['valor'];
				$cargados = 0.0;
				foreach ($saldos as $numero => $saldo) {
					if ($numero < $pie && !isset($descartes[$numero]) && !is_null($saldo)) {
						$cargados += $saldo;
					}
				}
				$sacando = $cargados;
				foreach (array_keys($candidatos) as $numero) {
					$sacando -= (float) $saldos[$numero];
				}
				if (abs($cargados - $total) > self::TOLERANCIA_DE_LOS_SALDOS && abs($sacando - $total) <= self::TOLERANCIA_DE_LOS_SALDOS) {
					foreach (array_keys($candidatos) as $numero) {
						$descartes[$numero] = ['total', (string) ImportHelper::getColumnValue($lista[$numero - 1], 'nombre', $columns)];
					}
				}
			}
		}
		ksort($descartes);

		return $descartes;
	}

	/**
	 * Lo que sigue a la palabra de total en el rótulo de un total («Total Barrio Norte» → «barrio norte»), ya
	 * comparable, o '' (sexta pasada, 4).
	 *
	 * @param  string $rotulo
	 * @return string
	 */
	static function titulo_del_total($rotulo) {
		$comparable = \App\Services\Importacion\PlanillaDePrueba::titulo_comparable($rotulo);
		$resto = trim((string) preg_replace('/^(?:sub ?total(?:es)?|total(?:es)?|sumas?)(?: de(?: la| los| las| el)?)?(?: |$)/', '', $comparable));

		return $resto === $comparable || preg_match('/^\d+(?: |$)/', $resto) === 1 ? '' : $resto;
	}

	/**
	 * Lo que hay que saber de la lista ENTERA antes de juzgar fila por fila (E8.2, rehecho en E8.3 y en la ronda 8).
	 *
	 *  - `con_datos`: cuántos contactos traen algún dato además del nombre (teléfono, CUIT, dirección, saldo…).
	 *  - `sin_senal`: cuántas filas traen SOLO el nombre y no tienen ninguna señal de título (para la señal débil).
	 *  - `solo_nombre`: las filas de solo nombre antes del pie (para la clara mayoría de los grupos).
	 *  - `nombres_en_mayusculas` / `nombres_en_minuscula`: cómo escribe la lista los nombres de sus contactos.
	 *  - `cabecera`: los títulos de la fila anterior a la de inicio, por columna mapeada, ya comparables.
	 *  - `ultima_con_datos`: la última fila con algún dato además del nombre (el total incluido).
	 *  - `primer_contacto_con_datos` / `ultimo_contacto_con_datos`: dónde empiezan y terminan los contactos con datos.
	 *  - `pie`: la fila del total que abre el pie, o null. Quinta pasada (A3): un total abre el pie solo si tiene arriba
	 *    al menos el 80 % de los contactos con datos; un total arriba o un subtotal en el medio no abren nada.
	 *  - `formato`: el formato de la celda del nombre, o [] si no es señal (quinta pasada, A2: ver formato_con_estructura()).
	 *
	 * @param  \Illuminate\Support\Collection|array $rows
	 * @param  array                                $columns
	 * @param  int                                  $start_row
	 * @param  int                                  $finish_row
	 * @param  array                                $formato
	 * @return array
	 */
	static function contexto_de_contactos($rows, $columns, $start_row, $finish_row, array $formato = []) {
		$con_datos_en_negrita = 0;
		$con_datos_en_minuscula = 0;
		$titulos_de_los_totales = [];
		$solo_nombre = [];
		$cabecera = [];
		$con_datos = 0;
		$mayusculas = 0;
		$ultima_con_datos = 0;
		$totales = [];
		$filas_con_datos = [];
		$sin_senal = [];
		// La secuencia de la zona de datos, para la estructura de los grupos con formato: [número, tipo, nombre].
		$secuencia = [];
		$numero = 0;
		foreach ($rows as $row) {
			$numero++;
			if ($numero == (int) $start_row - 1) {
				foreach ($columns as $clave => $indice) {
					$titulo = \App\Services\Importacion\PlanillaDePrueba::titulo_comparable(ImportHelper::getColumnValue($row, $clave, $columns));
					if ($titulo !== '') {
						$cabecera[$indice] = $titulo;
					}
				}
			}
			if ($numero < (int) $start_row || $numero > (int) $finish_row) {
				continue;
			}
			$otros = self::otros_datos_del_contacto($row, $columns) !== [];
			if ($otros) {
				$ultima_con_datos = $numero;
			}
			$nombre = ImportHelper::getColumnValue($row, 'nombre', $columns);
			if (is_null($nombre) || $nombre === '') {
				if (!is_null(self::total_sin_nombre($row, $columns))) {
					$totales[] = $numero;
					$secuencia[] = [$numero, 'otro', null];
				} else {
					$secuencia[] = [$numero, $otros ? 'otro' : 'vacia', null];
				}
				continue;
			}
			$motivo = self::motivo_de_fila_que_no_es_un_contacto($row, $columns, ['cabecera' => $cabecera]);
			if (!is_null($motivo)) {
				if ($motivo[0] === 'total') {
					$totales[] = $numero;
					$titulo = self::titulo_del_total($nombre);
					if ($titulo !== '') {
						$titulos_de_los_totales[$titulo] = true;
					}
				}
				$secuencia[] = [$numero, 'titulo', $nombre];
				continue;
			}
			if ($otros) {
				$con_datos++;
				$filas_con_datos[] = $numero;
				$con_datos_en_negrita += ((int) ($formato[$numero] ?? 0) & self::FORMATO_NEGRITA) !== 0 ? 1 : 0;
				$con_datos_en_minuscula += preg_match('/^\p{Lu}/u', trim($nombre)) === 1 ? 0 : 1;
				$mayusculas += self::en_mayusculas($nombre) ? 1 : 0;
				$secuencia[] = [$numero, 'dato', $nombre];
			} else {
				$secuencia[] = [$numero, 'solo_nombre', $nombre];
				// Las notas (forma de oración o anotación) no cuentan para la mayoría: quedan afuera por lo que son.
				if (!self::tiene_forma_de_oracion($nombre) && !self::es_anotacion($nombre)) {
					$solo_nombre[] = $numero;
					if (!self::senal_fuerte_de_titulo($nombre) && !self::senal_debil_de_titulo($nombre)) {
						$sin_senal[] = $numero;
					}
				}
			}
		}
		// El total que abre el pie: el primero con el 80 % de los contactos con datos arriba (en una lista sin contactos
		// con datos, el 80 % de las filas con nombre).
		$pie = null;
		if ($con_datos > 0) {
			foreach ($totales as $total) {
				$arriba = count(array_filter($filas_con_datos, function ($fila) use ($total) {
					return $fila < $total;
				}));
				if ($arriba * 5 >= $con_datos * 4) {
					$pie = $total;
					break;
				}
			}
		} elseif ($totales !== []) {
			// Sin contactos con datos no hay con qué medir: el último total abre el pie si tiene más filas con nombre
			// arriba que abajo (las de abajo pueden ser notas).
			$ultimo = (int) end($totales);
			$arriba = count(array_filter($solo_nombre, function ($fila) use ($ultimo) { return $fila < $ultimo; }));
			$abajo = count($solo_nombre) - $arriba;
			$pie = $arriba > $abajo ? $ultimo : null;
		}
		$ultimo_contacto = $filas_con_datos === [] ? 0 : (int) end($filas_con_datos);
		// Las notas al pie (solo nombre, después del total del pie y debajo del último contacto con datos) no cuentan.
		$antes_del_pie = function ($fila) use ($pie, $ultima_con_datos) {
			return is_null($pie) || $fila < $pie || $fila <= $ultima_con_datos;
		};
		$sin_senal = array_filter($sin_senal, $antes_del_pie);
		$solo_nombre = array_filter($solo_nombre, $antes_del_pie);
		$nombres_en_minuscula = $con_datos > 0 && $con_datos_en_minuscula * 2 > $con_datos;

		return [
			'con_datos'                 => $con_datos,
			'sin_senal'                 => count($sin_senal),
			'nombres_en_mayusculas'     => $mayusculas * 4 > $con_datos,
			'cabecera'                  => $cabecera,
			'ultima_con_datos'          => $ultima_con_datos,
			'primer_contacto_con_datos' => $filas_con_datos === [] ? 0 : (int) $filas_con_datos[0],
			'ultimo_contacto_con_datos' => $ultimo_contacto,
			'pie'                       => $pie,
			'solo_nombre'               => count($solo_nombre),
			'formato'                   => self::formato_con_estructura($formato, $secuencia, $con_datos, $con_datos_en_negrita, $nombres_en_minuscula),
			'con_datos_en_negrita'      => $con_datos_en_negrita,
			'nombres_en_minuscula'      => $nombres_en_minuscula,
			'titulos_de_los_totales'    => $titulos_de_los_totales,
		];
	}

	/** Grupos con formato como mucho, y contactos con datos por grupo como mínimo (quinta pasada, A2). */
	const MAXIMO_DE_GRUPOS_CON_FORMATO = 8;
	const CONTACTOS_CON_DATOS_POR_GRUPO_CON_FORMATO = 2;

	/**
	 * El formato es señal de un título de grupo solo si la lista tiene ESTRUCTURA de grupos (quinta pasada, A2): el
	 * dueño que pone en negrita a los clientes sin teléfono, dispersos, no está armando grupos.
	 *  (a) la lista arranca con un título: la primera fila de la zona de datos es una de solo nombre con formato o con
	 *      otra señal de título;
	 *  (b) cada fila de solo nombre con formato está seguida (salteando las vacías) por un contacto con datos, así que
	 *      no hay dos seguidas;
	 *  (c) son pocas: MAXIMO_DE_GRUPOS_CON_FORMATO como mucho, con CONTACTOS_CON_DATOS_POR_GRUPO_CON_FORMATO contactos con
	 *      datos por cada una, en promedio.
	 * La negrita cuenta solo si ningún contacto con datos está en negrita; la combinada ancha, siempre. Sin estructura,
	 * devuelve [] y ninguna fila sale por su formato.
	 *
	 * @return array  número de fila => bits de formato que valen como señal
	 */
	private static function formato_con_estructura(array $formato, array $secuencia, $con_datos, $con_datos_en_negrita, $nombres_en_minuscula) {
		$senal = function ($numero) use ($formato, $con_datos_en_negrita) {
			$bits = (int) ($formato[$numero] ?? 0);

			return ($bits & self::FORMATO_COMBINADA_ANCHA) !== 0 || (($bits & self::FORMATO_NEGRITA) !== 0 && $con_datos_en_negrita === 0);
		};
		$con_formato = [];
		foreach ($secuencia as $posicion => $item) {
			if ($item[1] === 'solo_nombre' && $senal($item[0])) {
				$con_formato[$posicion] = $item[0];
			}
		}
		if ($con_formato === [] || count($con_formato) > self::MAXIMO_DE_GRUPOS_CON_FORMATO
			|| $con_datos < count($con_formato) * self::CONTACTOS_CON_DATOS_POR_GRUPO_CON_FORMATO) {
			return [];
		}
		// (a) La primera fila con algo de la zona de datos es un título.
		$primera = null;
		foreach ($secuencia as $posicion => $item) {
			if ($item[1] !== 'vacia') {
				$primera = $posicion;
				break;
			}
		}
		$es_titulo = !is_null($primera) && (isset($con_formato[$primera]) || $secuencia[$primera][1] === 'titulo'
			|| ($secuencia[$primera][1] === 'solo_nombre' && !self::nunca_es_titulo($secuencia[$primera][2])
				&& (self::senal_fuerte_de_titulo($secuencia[$primera][2])
					|| (!self::parece_un_nombre_propio($secuencia[$primera][2]) && self::senal_de_grupo($secuencia[$primera][2], $nombres_en_minuscula)))));
		if (!$es_titulo) {
			return [];
		}
		// (b) Cada una seguida por un contacto con datos.
		foreach (array_keys($con_formato) as $posicion) {
			$siguiente = null;
			for ($i = $posicion + 1; $i < count($secuencia); $i++) {
				if ($secuencia[$i][1] !== 'vacia') {
					$siguiente = $secuencia[$i][1];
					break;
				}
			}
			if ($siguiente !== 'dato') {
				return [];
			}
		}

		return array_intersect_key($formato, array_flip(array_values($con_formato)));
	}

	/** Palabras con las que empieza una anotación en el medio de una lista (quinta pasada, A1). */
	const PALABRAS_DE_ANOTACION = ['ojo', 'nota', 'notas', 'obs', 'observacion', 'observaciones', 'atencion', 'importante',
		'recordar', 'ver', 'llamar', 'falta', 'faltan', 'actualizado', 'actualizada', 'precios', 'pendiente', 'pendientes',
		'revisar', 'avisar', 'consultar', 'aclaracion'];

	/**
	 * ¿La fila de solo nombre es una anotación? (quinta pasada, A1: la que vale en el MEDIO de la lista)
	 *  - empieza con una de PALABRAS_DE_ANOTACION seguida de «:» o de más texto que empieza en minúscula («ojo: este
	 *    paga los viernes», «Avisar a Marta que…», «Precios sujetos a cambio»); o
	 *  - tiene marcas claras de anotación: va toda entre paréntesis, o empieza con «*», «//», «->», «>>» o «NB».
	 * «Ver Pérez» o «Falta Gómez» (con mayúscula después) son nombres.
	 *
	 * @param  string $nombre
	 * @return bool
	 */
	static function es_anotacion($nombre) {
		$texto = trim((string) $nombre);
		// Sexta pasada: una marca («(…)», «*», «//», «->», «>>», «NB») sola no alcanza («* Carla», «NB Carla» son
		// clientes marcados): lo de adentro tiene que ser además una anotación o tener forma de oración.
		$sin_marca = trim((string) preg_replace('/^(?:\*+|\/\/|->|>>|nb\b:?)\s*/iu', '', $texto));
		if (preg_match('/^\((.*)\)$/u', $texto, $partes) === 1) {
			$sin_marca = trim($partes[1]);
		}
		if ($sin_marca !== $texto) {
			return $sin_marca !== '' && (self::es_anotacion($sin_marca) || self::tiene_forma_de_oracion($sin_marca));
		}
		$palabras = preg_split('/\s+/u', $texto);
		$primera = \App\Services\Importacion\PlanillaDePrueba::titulo_comparable(rtrim($palabras[0], ':'));
		if (!in_array($primera, self::PALABRAS_DE_ANOTACION, true)) {
			return false;
		}
		if (substr($palabras[0], -1) === ':' || preg_match('/^\S+\s*:/u', $texto) === 1) {
			return true;
		}

		return count($palabras) >= 2 && preg_match('/^\p{Ll}/u', $palabras[1]) === 1;
	}

	/**
	 * Por qué una fila de una lista de clientes o proveedores no es un contacto, o null si lo es (E8.1 a E8.3).
	 * Devuelve [motivo, texto]: motivo 'total', 'titulo' o 'nota'; el texto es el nombre de la fila, para el aviso.
	 *
	 *  - 'total': el rótulo es de total (PlanillaDePrueba::es_rotulo_de_total(): «TOTAL», «Subtotal zona norte»,
	 *    «Total: 21 clientes», «Son 21 clientes»), o 'titulo' si es un título decorado («--- MAYORISTAS ---»); en los
	 *    dos casos sin dato en ninguna columna que identifica a un contacto y con el resto solo números o nada.
	 *  - 'titulo' por cabecera repetida: dos o más celdas mapeadas con dato y TODAS iguales al título de su columna.
	 *  - Con SOLO el nombre (ninguna otra columna mapeada con dato: ni teléfono, CUIT, saldo, nada; el saldo SÍ es
	 *    un dato: «Sandra Ojeda | | | 1500» es un cliente):
	 *      · 'nota': ya pasó el total y no hay filas con datos debajo (las notas al pie).
	 *      · 'titulo' con señal FUERTE (senal_fuerte_de_titulo()) y cinco palabras como mucho, si la lista tiene
	 *        al menos CONTACTOS_CON_DATOS_PARA_TITULOS_CON_SENAL contactos con datos. Sin proporción: la señal ya
	 *        alcanza.
	 *      · 'titulo' con señal DÉBIL (senal_debil_de_titulo(): todo en mayúsculas en una lista que no escribe así
	 *        sus nombres), solo si hay al menos CONTACTOS_CON_DATOS_PARA_TITULOS_EN_MAYUSCULAS contactos con datos y
	 *        son 3 de cada 4 de los contactos que se juzgan (con datos + de solo nombre sin señal).
	 *      · NUNCA es título una razón social ni un cliente genérico de mostrador (nunca_es_titulo()).
	 *    🔴 Sin señal, un nombre solo es un contacto sin datos y entra. Todo lo descartado sale nombrado en el aviso.
	 *
	 * @param  array|\ArrayAccess $row
	 * @param  array              $columns
	 * @param  array              $contexto          El de contexto_de_contactos(); [] = solo totales y decorados
	 * @param  int                $numero            Número de la fila (1-based)
	 * @param  bool               $despues_del_total Si la lista ya pasó por su total
	 * @return array|null
	 */
	static function motivo_de_fila_que_no_es_un_contacto($row, $columns, array $contexto = [], $numero = 0, $despues_del_total = false) {
		$nombre = ImportHelper::getColumnValue($row, 'nombre', $columns);
		if (is_null($nombre) || $nombre === '') {
			return null;
		}
		$otros = self::otros_datos_del_contacto($row, $columns);

		// Sexta pasada: sin tope de palabras («Total Villa del Parque», «Subtotal talleres y flotas»), salvo una razón
		// social o un genérico («Total Hogar SRL» entra).
		$es_total = !self::nunca_es_titulo($nombre) && (\App\Services\Importacion\PlanillaDePrueba::es_rotulo_de_total($nombre)
			|| \App\Services\Importacion\PlanillaDePrueba::empieza_con_palabra_de_total($nombre));
		if ($es_total || \App\Services\Importacion\PlanillaDePrueba::es_titulo_decorado($nombre)) {
			foreach ($otros as $clave => $valor) {
				if (in_array($clave, self::COLUMNAS_QUE_IDENTIFICAN_UN_CONTACTO, true)) {
					return null;
				}
				try {
					if (is_null(ImportHelper::parseNumericValue($valor))) {
						return null;
					}
				} catch (\InvalidArgumentException $e) {
					return null;
				}
			}

			return [$es_total ? 'total' : 'titulo', $nombre];
		}

		$cabecera = isset($contexto['cabecera']) ? $contexto['cabecera'] : [];
		if ($cabecera !== [] && self::repite_la_cabecera_del_contacto($row, $columns, $cabecera)) {
			return ['titulo', $nombre];
		}

		if ($otros !== [] || !isset($contexto['con_datos'])) {
			return null;
		}
		if ($despues_del_total && (int) $numero > (int) $contexto['ultima_con_datos']) {
			return ['nota', $nombre];
		}
		// Quinta pasada (A1): la forma de oración vale solo en la cabecera (arriba del primer contacto con datos) y en el pie
		// (debajo del último); en el medio, solo una anotación clara («ojo: …», «Avisar a…»). «Pérez, el de la esquina»
		// entre clientes es un cliente.
		if ($contexto['con_datos'] >= self::CONTACTOS_CON_DATOS_PARA_TITULOS_CON_SENAL) {
			$afuera_de_los_datos = (int) $numero < (int) $contexto['primer_contacto_con_datos']
				|| (int) $numero > (int) $contexto['ultimo_contacto_con_datos'];
			if (($afuera_de_los_datos && self::tiene_forma_de_oracion($nombre)) || self::es_anotacion($nombre)) {
				return ['nota', $nombre];
			}
		}
		// Sexta pasada: el título de un grupo que se repite en el rótulo de su total («Barrio Norte» ↔ «Total Barrio Norte»).
		$comparable = \App\Services\Importacion\PlanillaDePrueba::titulo_comparable($nombre);
		if ($comparable !== '' && isset($contexto['titulos_de_los_totales'][$comparable])) {
			return ['titulo', $nombre];
		}
		if (count(preg_split('/\s+/u', trim($nombre))) > 5) {
			return null;
		}
		if (self::senal_fuerte_de_titulo($nombre)) {
			return $contexto['con_datos'] >= self::CONTACTOS_CON_DATOS_PARA_TITULOS_CON_SENAL ? ['titulo', $nombre] : null;
		}
		$proporcion = $contexto['con_datos'] >= self::CONTACTOS_CON_DATOS_PARA_TITULOS_EN_MAYUSCULAS
			&& $contexto['con_datos'] * 4 >= ($contexto['con_datos'] + $contexto['sin_senal']) * 3;
		if ($proporcion && !$contexto['nombres_en_mayusculas'] && self::senal_debil_de_titulo($nombre)) {
			return ['titulo', $nombre];
		}
		if (self::es_fila_suelta($nombre, $contexto, (int) ($contexto['formato'][(int) $numero] ?? 0))) {
			return ['suelta', $nombre];
		}

		return null;
	}

	/**
	 * Con cuántos contactos con algún dato además del nombre, como mínimo, una lista se juzga «con datos» para dejar
	 * afuera una fila de solo nombre con señal de grupo (ver es_fila_suelta()).
	 */
	const CONTACTOS_CON_DATOS_PARA_SUELTAS = 5;

	/**
	 * Clara mayoría (ronda 8, cuarta pasada): por cada fila de solo nombre de la lista (con señal o sin ella), al menos
	 * esta cantidad de contactos con datos. Sin tope de filas: con cuatro grupos y nueve clientes, los cuatro afuera.
	 */
	const CONTACTOS_CON_DATOS_POR_FILA_DE_SOLO_NOMBRE = 2;

	/** Formato de la celda del nombre (bits): en negrita, y combinada con tres columnas o más. */
	const FORMATO_NEGRITA = 1;
	const FORMATO_COMBINADA_ANCHA = 2;

	/**
	 * El vocabulario cerrado de agrupación: la PRIMERA palabra de un título de grupo, ya comparable (sin acentos, en
	 * minúscula) y en singular. «cuenta» y «cta» valen solo seguidas de «corriente»/«cte». Sin nombres ni apodos
	 * habituales (cuarta pasada: salieron «nuevo», «viejo», «contado» y «activo»).
	 */
	const PALABRAS_DE_GRUPO = ['cliente', 'proveedor', 'empresa', 'comercio', 'mayorista', 'minorista', 'particular',
		'revendedor', 'distribuidor', 'gremio', 'vecino', 'deudor', 'moroso', 'inactivo', 'vario', 'otro', 'resto', 'zona',
		'barrio', 'obra', 'consumidor', 'lista', 'listado', 'cuenta', 'cta'];

	/** Preposiciones y contracciones con las que empieza un título de grupo en minúscula («Del barrio», «Por zona»). */
	const PREPOSICIONES_DE_GRUPO = ['de', 'del', 'para', 'por', 'con', 'sin', 'a', 'al', 'en', 'desde', 'hasta'];

	/**
	 * ¿Una fila de SOLO nombre es un título de grupo (motivo «suelta») y no un contacto? (misión
	 * prueba-e2e-campana-imperio, ronda 8, A1; cuarta pasada)
	 *
	 * 🔴 Una fila de solo nombre ENTRA, como siempre, salvo que tenga una señal POSITIVA de grupo, y todo esto:
	 *  1. La lista trae al menos CONTACTOS_CON_DATOS_PARA_SUELTAS contactos con datos, y son clara mayoría: al menos
	 *     CONTACTOS_CON_DATOS_POR_FILA_DE_SOLO_NOMBRE por cada fila de solo nombre (`solo_nombre`). Sin tope de grupos.
	 *  2. No es razón social ni cliente genérico (nunca_es_titulo()), no tiene números y no está toda en mayúsculas
	 *     (eso lo juzga la señal débil, que ya existía).
	 *  3. La señal (senal_de_grupo()), una de estas:
	 *      · FORMATO: la celda del nombre en negrita, si NINGÚN contacto con datos está en negrita; o combinada con
	 *        tres columnas o más. El relleno de color no es señal (así se marcan morosos y filas alternadas), y dos
	 *        celdas vecinas combinadas tampoco.
	 *      · VOCABULARIO: no se escribe como nombre propio, la primera palabra en singular es de PALABRAS_DE_GRUPO y, si
	 *        hay más palabras, van en minúscula («Clientes del taller»). En una lista que escribe sus nombres en minúscula
	 *        («juan gomez») la minúscula no dice nada: ahí vale solo una palabra sola («mayoristas»).
	 *      · PREPOSICIÓN: empieza con una de PREPOSICIONES_DE_GRUPO y lo que sigue (al menos una palabra) va en
	 *        minúscula («Del barrio»); con mayúscula es un apellido («Del Valle»). En una lista en minúscula no vale.
	 * Queda afuera NOMBRADA en el aviso.
	 *
	 * @param  string $nombre
	 * @param  array  $contexto  El de contexto_de_contactos()
	 * @param  int    $formato   Bits FORMATO_* de la celda del nombre
	 * @return bool
	 */
	private static function es_fila_suelta($nombre, array $contexto, $formato = 0) {
		$con_datos = (int) $contexto['con_datos'];
		$solo_nombre = (int) ($contexto['solo_nombre'] ?? 0);
		if ($con_datos < self::CONTACTOS_CON_DATOS_PARA_SUELTAS
			|| $con_datos < $solo_nombre * self::CONTACTOS_CON_DATOS_POR_FILA_DE_SOLO_NOMBRE) {
			return false;
		}
		if (self::nunca_es_titulo($nombre) || self::en_mayusculas($nombre) || preg_match('/\d/u', $nombre) === 1) {
			return false;
		}
		if (($formato & self::FORMATO_COMBINADA_ANCHA) !== 0
			|| (($formato & self::FORMATO_NEGRITA) !== 0 && empty($contexto['con_datos_en_negrita']))) {
			return true;
		}

		return !self::parece_un_nombre_propio($nombre) && self::senal_de_grupo($nombre, !empty($contexto['nombres_en_minuscula']));
	}

	/**
	 * La señal de vocabulario o de preposición (ver es_fila_suelta(), punto 3).
	 *
	 * @param  string $nombre
	 * @param  bool   $lista_en_minuscula  La lista escribe sus nombres en minúscula: la minúscula no distingue
	 * @return bool
	 */
	private static function senal_de_grupo($nombre, $lista_en_minuscula = false) {
		$originales = preg_split('/\s+/u', trim($nombre));
		$palabras = explode(' ', \App\Services\Importacion\PlanillaDePrueba::titulo_comparable($nombre));
		if ($palabras === [] || $palabras[0] === '' || count($originales) !== count($palabras)) {
			return false;
		}
		if (count($palabras) > 1 && $lista_en_minuscula) {
			return false;
		}
		// Lo que sigue a la primera palabra va todo en minúscula: si no, es un nombre o un apellido.
		foreach (array_slice($originales, 1) as $palabra) {
			if (preg_match('/\p{Lu}/u', $palabra) === 1) {
				return false;
			}
		}
		$primera = $palabras[0];
		if (in_array($primera, self::PREPOSICIONES_DE_GRUPO, true)) {
			return count($palabras) >= 2;
		}
		$singular = in_array($primera, self::PALABRAS_DE_GRUPO, true) ? $primera
			: (in_array(preg_replace('/es$/', '', $primera), self::PALABRAS_DE_GRUPO, true) ? preg_replace('/es$/', '', $primera)
				: preg_replace('/s$/', '', $primera));
		if (!in_array($singular, self::PALABRAS_DE_GRUPO, true)) {
			return false;
		}
		if ($singular === 'cuenta' || $singular === 'cta') {
			return isset($palabras[1]) && in_array($palabras[1], ['corriente', 'corrientes', 'cte', 'ctes'], true);
		}

		return true;
	}

	/**
	 * ¿El texto de una fila de solo nombre tiene forma de oración (una nota), no de nombre? (cuarta pasada, A4)
	 *  - con coma, punto y coma o dos puntos seguidos de espacio, o punto final, y al menos tres palabras que empiezan
	 *    en minúscula («Fulana dejó plata de más, descontarle…»); o
	 *  - siete palabras o más, la mitad o más empezando en minúscula.
	 * «Consorcio Edificio Rivadavia 845» y «Cooperativa de Trabajo La Unión Limitada» (6 palabras, 1 en minúscula), o
	 * «Gómez, Jorge (albañil)», son nombres. Nunca una razón social.
	 *
	 * @param  string $nombre
	 * @return bool
	 */
	static function tiene_forma_de_oracion($nombre) {
		$palabras = preg_split('/\s+/u', trim((string) $nombre));
		$minusculas = count(array_filter($palabras, function ($palabra) {
			return preg_match('/^\p{Ll}/u', $palabra) === 1;
		}));
		if (self::nunca_es_titulo($nombre)) {
			return false;
		}
		$puntuada = preg_match('/[,;:]\s|\.\s|\.$/u', trim((string) $nombre)) === 1;

		return ($puntuada && $minusculas >= 3) || (count($palabras) >= 7 && $minusculas * 2 >= count($palabras));
	}

	/**
	 * La fila del total SIN nombre (el rótulo en otra columna: «| TOTAL | | 47.350,50»): devuelve el rótulo, o null.
	 * Un solo texto en toda la fila, que es un rótulo de total, y el resto números o nada (cuarta pasada, A4 y A5).
	 *
	 * @param  array|\ArrayAccess $row
	 * @param  array              $columns
	 * @return string|null
	 */
	static function total_sin_nombre($row, $columns) {
		$nombre = ImportHelper::getColumnValue($row, 'nombre', $columns);
		if (!is_null($nombre) && $nombre !== '') {
			return null;
		}
		$celdas = is_object($row) && method_exists($row, 'toArray') ? $row->toArray() : (array) $row;
		$rotulo = null;
		foreach ($celdas as $celda) {
			$texto = trim((string) $celda);
			if ($texto === '') {
				continue;
			}
			$numero = null;
			try {
				$numero = ImportHelper::parseNumericValue($texto);
			} catch (\InvalidArgumentException $e) {
				$numero = null;
			}
			if (!is_null($numero)) {
				continue;
			}
			if (!is_null($rotulo) || !\App\Services\Importacion\PlanillaDePrueba::es_rotulo_de_total($texto)) {
				return null;
			}
			$rotulo = $texto;
		}

		return $rotulo;
	}

	/**
	 * ¿El nombre se presenta como título de sección (señal FUERTE)? (E8.2, ajustado en E8.4)
	 *  - termina en «:» («Zona norte:», «Proveedores de obra:»); o
	 *  - empieza con una palabra de sección (clientes, proveedores, deudores, morosos, zona, listado, lista, rubro,
	 *    sección, grupo, categoría, sucursal, al día, cuentas), tiene a lo sumo tres palabras y NO tiene forma de
	 *    nombre propio: «Zona centro», «Morosos», «Clientes del interior» sí; «Zona Sur Materiales», «Grupo Lopez»,
	 *    «Sucursal Centro», «Rubro Hogar» (cada palabra con mayúscula inicial, como se escribe un negocio) no.
	 * Nunca una razón social ni un cliente genérico (nunca_es_titulo()).
	 *
	 * @param  string $nombre
	 * @return bool
	 */
	private static function senal_fuerte_de_titulo($nombre) {
		if (self::nunca_es_titulo($nombre)) {
			return false;
		}
		if (substr(rtrim($nombre), -1) === ':') {
			return true;
		}
		$comparable = \App\Services\Importacion\PlanillaDePrueba::titulo_comparable($nombre);

		return preg_match('/^(?:clientes|proveedores|deudores|morosos|zona|zonas|listado|lista|rubro|rubros|seccion|grupo|categoria|sucursal|al dia|cuentas)(?: |$)/', $comparable) === 1
			&& count(explode(' ', $comparable)) <= 3
			&& !self::parece_un_nombre_propio($nombre);
	}

	/**
	 * ¿El nombre está todo en mayúsculas (señal DÉBIL de título)? No cuenta una sigla de una sola palabra corta
	 * (cinco letras o menos: «YPF», «ACA») ni una razón social o un cliente genérico (nunca_es_titulo()).
	 *
	 * @param  string $nombre
	 * @return bool
	 */
	private static function senal_debil_de_titulo($nombre) {
		$palabras = preg_split('/\s+/u', trim($nombre));
		if (count($palabras) === 1 && mb_strlen(preg_replace('/[^\pL]/u', '', $nombre)) <= 5) {
			return false;
		}

		return !self::nunca_es_titulo($nombre) && self::en_mayusculas($nombre);
	}

	/**
	 * ¿El nombre NUNCA puede ser un título? Tiene forma de razón social (SA, SRL, SAS, SH, SCS, Hnos, «y Cía», «e
	 * Hijos», como palabra) o es un cliente genérico de mostrador («Clientes varios», «Consumidor final», «Varios»,
	 * «Mostrador», «Público en general», «Cliente mostrador»).
	 *
	 * @param  string $nombre
	 * @return bool
	 */
	private static function nunca_es_titulo($nombre) {
		$comparable = \App\Services\Importacion\PlanillaDePrueba::titulo_comparable($nombre);

		return preg_match('/(?:^| )(?:sa|srl|sas|sh|scs|hnos|hermanos)(?: |$)|(?:^| )y cia(?: |$)|(?:^| )e hijos(?: |$)/', $comparable) === 1
			|| in_array($comparable, ['clientes varios', 'cliente varios', 'consumidor final', 'varios', 'mostrador', 'publico en general',
				'cliente mostrador', 'clientes mostrador', 'cliente generico', 'consumidor final mostrador'], true);
	}

	/**
	 * ¿Se escribe como el nombre de un negocio o una persona? Dos palabras o más (sin contar de, del, la, las, los,
	 * el, y, e) y todas con mayúscula inicial y alguna minúscula: «Zona Sur Materiales», «Grupo Lopez».
	 *
	 * @param  string $nombre
	 * @return bool
	 */
	private static function parece_un_nombre_propio($nombre) {
		$palabras = array_values(array_filter(preg_split('/\s+/u', trim($nombre)), function ($palabra) {
			return !in_array(mb_strtolower($palabra, 'UTF-8'), ['de', 'del', 'la', 'las', 'los', 'el', 'y', 'e'], true);
		}));
		if (count($palabras) < 2) {
			return false;
		}
		foreach ($palabras as $palabra) {
			if (preg_match('/^\p{Lu}.*\p{Ll}/u', $palabra) !== 1) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Las columnas mapeadas, salvo el nombre, que traen dato en la fila: clave => valor.
	 *
	 * @param  array|\ArrayAccess $row
	 * @param  array              $columns
	 * @return array
	 */
	private static function otros_datos_del_contacto($row, $columns) {
		$otros = [];
		foreach ($columns as $clave => $indice) {
			if ($clave === 'nombre') {
				continue;
			}
			$valor = ImportHelper::getColumnValue($row, $clave, $columns);
			if (!is_null($valor) && $valor !== '') {
				$otros[$clave] = $valor;
			}
		}

		return $otros;
	}

	/**
	 * ¿La fila repite la cabecera? Dos o más celdas mapeadas con dato, y todas iguales al título de su columna.
	 *
	 * @param  array|\ArrayAccess $row
	 * @param  array              $columns
	 * @param  array              $cabecera  Índice => título comparable
	 * @return bool
	 */
	private static function repite_la_cabecera_del_contacto($row, $columns, array $cabecera) {
		$iguales = 0;
		foreach ($columns as $clave => $indice) {
			$valor = ImportHelper::getColumnValue($row, $clave, $columns);
			if (is_null($valor) || $valor === '') {
				continue;
			}
			if (!isset($cabecera[$indice]) || \App\Services\Importacion\PlanillaDePrueba::titulo_comparable($valor) !== $cabecera[$indice]) {
				return false;
			}
			$iguales++;
		}

		return $iguales >= 2;
	}

	/**
	 * ¿El texto está todo en mayúsculas? Con al menos dos letras mayúsculas («MOROSOS», «AL DÍA»).
	 *
	 * @param  string $texto
	 * @return bool
	 */
	private static function en_mayusculas($texto) {
		return preg_match('/\p{Lu}.*\p{Lu}/u', $texto) === 1 && mb_strtoupper($texto, 'UTF-8') === $texto;
	}

	/**
	 * El bloque del aviso de la importación con las filas que no se cargaron por no ser contactos (ver
	 * fila_que_no_es_un_contacto()), o null si no hubo.
	 *
	 * @param  array  $filas   [['fila' => int, 'nombre' => string], ...]
	 * @param  string $modelo  'clientes' o 'proveedores'
	 * @return array|null
	 */
	static function bloque_de_filas_que_no_son_contactos(array $filas, $modelo) {
		if (count($filas) == 0) {
			return null;
		}
		$parrafos = [];
		foreach (array_slice($filas, 0, self::MAXIMO_DE_FILAS_EN_EL_AVISO_DE_SALDOS_ILEGIBLES) as $fila) {
			$parrafos[] = 'Fila ' . $fila['fila'] . ': "' . self::recortar_para_el_aviso($fila['nombre'], self::LARGO_MAXIMO_DEL_NOMBRE_EN_EL_AVISO) . '"';
		}
		$restantes = count($filas) - self::MAXIMO_DE_FILAS_EN_EL_AVISO_DE_SALDOS_ILEGIBLES;
		if ($restantes > 0) {
			$parrafos[] = 'y ' . $restantes . ($restantes == 1 ? ' fila más' : ' filas más');
		}
		$parrafos[] = 'No las cargué como ' . $modelo . ': son el total, un subtotal o un título de la planilla. '
			. 'Si alguna era uno de tus ' . $modelo . ', cargalo a mano o avisanos.';

		return [
			'title'    => 'Filas que no son ' . $modelo,
			'parrafos' => $parrafos,
		];
	}

	/**
	 * Arma el bloque "Saldos del Excel que no se pudieron leer" de la notificación de fin de
	 * importación (formato `title` + `parrafos`, el que el SPA ya renderiza). Lo usan ClientImport y
	 * ProviderImport, cada uno con las filas que juntó.
	 *
	 * Un párrafo por fila: `Fila 7, Juan Pérez: "s/d"`. Comillas RECTAS a propósito: Pusher escapa
	 * cada caracter no ASCII a `\uXXXX` (seis bytes) y el evento tiene un tope de 10 KB. Por lo mismo
	 * el nombre se corta a LARGO_MAXIMO_DEL_NOMBRE_EN_EL_AVISO caracteres y el texto a
	 * LARGO_MAXIMO_DEL_TEXTO_EN_EL_AVISO, y se nombran como mucho $tope filas; si hay más, "y N filas
	 * más" (nombradas + N = todas). La explicación, en el idioma del comerciante, va al final.
	 *
	 * El $tope lo baja avisos_dentro_del_presupuesto() cuando los avisos no entran en Pusher.
	 *
	 * @param array $saldos_ilegibles Filas como [['fila' => int, 'nombre' => string, 'texto' => string], ...],
	 *                                en el orden del Excel.
	 * @param int|null $tope Cuántas filas nombrar. Null: MAXIMO_DE_FILAS_EN_EL_AVISO_DE_SALDOS_ILEGIBLES.
	 * @return array|null El bloque, o null si no hubo ninguna fila ilegible (no se avisa nada).
	 */
	static function bloque_de_saldos_ilegibles($saldos_ilegibles, $tope = null) {
		if (count($saldos_ilegibles) == 0) {
			return null;
		}

		if (is_null($tope)) {
			$tope = self::MAXIMO_DE_FILAS_EN_EL_AVISO_DE_SALDOS_ILEGIBLES;
		}

		// Nunca menos de una fila nombrada: un aviso que solo cuenta no dice dónde corregir.
		$tope = max(1, (int) $tope);

		$parrafos = [];

		foreach (array_slice($saldos_ilegibles, 0, $tope) as $ilegible) {
			$parrafos[] = 'Fila ' . $ilegible['fila']
				. ', ' . self::recortar_para_el_aviso($ilegible['nombre'], self::LARGO_MAXIMO_DEL_NOMBRE_EN_EL_AVISO)
				. ': "' . self::recortar_para_el_aviso($ilegible['texto'], self::LARGO_MAXIMO_DEL_TEXTO_EN_EL_AVISO) . '"';
		}

		$restantes = count($saldos_ilegibles) - $tope;

		if ($restantes > 0) {
			$parrafos[] = 'y ' . $restantes . ($restantes == 1 ? ' fila más' : ' filas más');
		}

		$parrafos[] = 'El saldo de esas filas no se cargó y su cuenta corriente quedó como estaba; el resto de sus datos sí se importó. '
			. 'Escribí el saldo solo con números (por ejemplo 52000,50, 52.000,50 o -7600); si es una fórmula, copiala y pegala como valor. '
			. 'Un saldo en dólares no se importa: esta columna carga la cuenta en pesos. '
			. 'Después volvé a importar el archivo.';

		return [
			'title'    => 'Saldos del Excel que no se pudieron leer',
			'parrafos' => $parrafos,
		];
	}

	/**
	 * Corta un texto a $maximo caracteres (no bytes) y le agrega "…" si lo cortó. Lo usan los dos
	 * bloques de avisos de saldo (el de ilegibles y el de "no se cargaron" de ProviderImport).
	 *
	 * 🔴 Antes de medir, sanea el texto a UTF-8 válido (mb_scrub(): cada byte roto pasa a "?"). El
	 * nombre o el texto vienen del Excel, y un solo byte inválido hace que json_encode() devuelva
	 * false: el evento de Pusher no se arma y se pierde la notificación entera.
	 *
	 * @param string|null $texto
	 * @param int $maximo
	 * @return string
	 */
	static function recortar_para_el_aviso($texto, $maximo) {
		$texto = mb_scrub((string) $texto, 'UTF-8');

		if (mb_strlen($texto, 'UTF-8') <= $maximo) {
			return $texto;
		}

		return mb_substr($texto, 0, $maximo, 'UTF-8') . '…';
	}

	/**
	 * Arma los bloques de avisos de la notificación de fin de importación (`info_to_show`) de forma
	 * que entren en PRESUPUESTO_DE_BYTES_DE_LOS_AVISOS, achicando cuántos nombra cada bloque "con
	 * tope". Lo usan ProviderImport (sus dos bloques de saldos) y ClientImport (el de ilegibles).
	 *
	 * Cómo:
	 *   1. Arma cada bloque con su tope inicial (el del caso típico: 50 proveedores, 20 filas).
	 *   2. Si `strlen(json_encode($info_to_show))` entra en el presupuesto, listo. Es el caso de
	 *      todos los días, y ahí no cambia nada.
	 *   3. Si no entra, le baja uno al tope del bloque que MÁS pesa (de los que todavía nombran más
	 *      de uno) y vuelve a armar todo. Repite hasta que entre.
	 *   4. Nunca nombra menos de uno por bloque: si con todos en uno sigue sin entrar, devuelve eso.
	 *
	 * Cada bloque se arma con su propio constructor, que cuenta los restantes ("y N ... más") contra
	 * el TOTAL: achicar el tope cambia cuántos se nombran, nunca cuántos se cuentan.
	 *
	 * ⚠️ Lo que NO garantiza: los bloques FIJOS (hoy, las sucursales que no existen de ClientImport)
	 * no tienen tope ni se achican, así que solos pueden dejar el evento afuera del presupuesto y de
	 * Pusher (con 400 sucursales inexistentes son unos 98 KB). Es previo a esta misión. Lo único
	 * que se les hace acá es sanear sus textos a UTF-8 válido (sanear_bloque_fijo()) antes de medir,
	 * para que un byte roto del Excel no haga fallar json_encode() y se pierda la notificación.
	 *
	 * @param array $bloques_fijos Bloques ya armados que van primero y no se achican (el de sucursales
	 *                             de ClientImport). Pueden estar vacíos. Se devuelven saneados.
	 * @param array $bloques_con_tope En el orden en que van, como
	 *                                [clave => ['tope' => int, 'total' => int, 'armar' => callable(int $tope): array|null], ...].
	 *                                `armar` devuelve null si el bloque no tiene nada que avisar.
	 * @return array El `info_to_show`: los fijos y después los que no son null.
	 */
	static function avisos_dentro_del_presupuesto(array $bloques_fijos, array $bloques_con_tope) {
		// Los fijos traen texto del Excel sin cortar: al menos que sean UTF-8 válido.
		$bloques_fijos = array_map(function ($bloque) {
			return self::sanear_bloque_fijo($bloque);
		}, $bloques_fijos);

		// Tope de cada bloque. Nunca más que lo que hay para nombrar: bajar un tope que no corta nada
		// no achica el aviso. Nunca menos de uno.
		$topes = [];

		foreach ($bloques_con_tope as $clave => $bloque) {
			$topes[$clave] = max(1, min((int) $bloque['tope'], (int) $bloque['total']));
		}

		while (true) {
			$armados = [];

			foreach ($bloques_con_tope as $clave => $bloque) {
				$armados[$clave] = call_user_func($bloque['armar'], $topes[$clave]);
			}

			$info_to_show = $bloques_fijos;

			foreach ($armados as $armado) {
				if (!is_null($armado)) {
					$info_to_show[] = $armado;
				}
			}

			$json = json_encode($info_to_show);

			if ($json === false) {
				// No debería pasar: los textos de los bloques, fijos y con tope, ya vienen saneados.
				Log::warning('LocalImportHelper::avisos_dentro_del_presupuesto - json_encode fallo: ' . json_last_error_msg());

				return $info_to_show;
			}

			if (strlen($json) <= self::PRESUPUESTO_DE_BYTES_DE_LOS_AVISOS) {
				return $info_to_show;
			}

			// El bloque que más pesa, de los que todavía nombran más de uno.
			$clave_a_achicar = null;
			$peso_mayor = -1;

			foreach ($armados as $clave => $armado) {
				if (is_null($armado) || $topes[$clave] <= 1) {
					continue;
				}

				$peso = strlen((string) json_encode($armado));

				if ($peso > $peso_mayor) {
					$peso_mayor = $peso;
					$clave_a_achicar = $clave;
				}
			}

			// Todos en uno: no hay nada más que achicar.
			if (is_null($clave_a_achicar)) {
				return $info_to_show;
			}

			$topes[$clave_a_achicar]--;
		}
	}

	/**
	 * Sanea a UTF-8 válido todos los textos de un bloque fijo de avisos (título y párrafos), con
	 * mb_scrub(): cada byte roto pasa a "?". No corta nada (ver avisos_dentro_del_presupuesto()).
	 *
	 * @param mixed $bloque ['title' => string, 'parrafos' => [string, ...]] u otra forma: se recorre
	 *                      entero y solo se tocan los strings.
	 * @return mixed El bloque saneado.
	 */
	private static function sanear_bloque_fijo($bloque) {
		if (is_string($bloque)) {
			return mb_scrub($bloque, 'UTF-8');
		}

		if (is_array($bloque)) {
			array_walk_recursive($bloque, function (&$valor) {
				if (is_string($valor)) {
					$valor = mb_scrub($valor, 'UTF-8');
				}
			});
		}

		return $bloque;
	}

	/**
	 * Obtiene la cuenta corriente en pesos del modelo importado.
	 *
	 * @param string $model_name Nombre del modelo (`client` o `provider`).
	 * @param int $model_id ID del registro.
	 * @return \App\Models\CreditAccount|null
	 */
	static function get_credit_account_pesos($model_name, $model_id) {
		return CreditAccount::where('model_name', $model_name)
			->where('model_id', $model_id)
			->where('moneda_id', 1)
			->first();
	}

	/**
	 * Crea el saldo inicial cuando el registro importado aún no tiene movimientos en pesos.
	 *
	 * Solo carga en una cuenta VACÍA: si la cuenta ya tiene algún movimiento (provisorios
	 * incluidos, igual que el botón "Saldo inicial"), no hace nada y devuelve false. Nunca ajusta;
	 * el ajuste de un cliente existente es ajustarSaldoPorImportacion(), y lo decide
	 * procesarSaldoImportacion(), no esta función.
	 *
	 * 🔴 El monto va SIEMPRE en positivo, del lado que corresponde: la deuda en el `debe` y el saldo
	 * a favor en el `haber`. Antes un saldo negativo del Excel (a favor) se guardaba con el `haber`
	 * NEGATIVO: el movimiento, la cuenta y el `saldo_pesos` quedaban bien al cargarse, pero la
	 * cadena resta el haber (`CurrentAcountHelper::aporte_al_saldo()` = −haber), así que el primer
	 * `checkSaldos()` —cualquier pago, venta o nota posterior— daba vuelta el signo: −7.600 a favor
	 * pasaba a +7.600 de deuda (misión importacion-proveedores-saldo-inicial, 8/10/2026; es lo de
	 * los "Saldo inicial" de Servian que destapó la tanda 4.2.5). Ahora el monto queda del mismo
	 * lado y con el mismo signo que el del botón (`CurrentAcountController::saldoInicial()`). Lo ya
	 * importado con el signo viejo no se toca acá.
	 *
	 * No es el mismo camino que el botón: el botón rechaza un monto 0 y carga el movimiento con el
	 * candado de la cuenta tomado; esta función no toma candado y, si le llega un 0, lo carga como
	 * un `debe` 0. La importación de proveedores no le pasa nunca un 0 (setSaldoInicial() lo
	 * filtra antes); la de clientes (procesarSaldoImportacion()) sí.
	 *
	 * El `saldo` del movimiento, el de la cuenta y el `saldo_pesos` sí llevan el signo del Excel:
	 * son saldos, no montos.
	 *
	 * Va SIN `user_id`, a propósito: ver el comentario del create en
	 * `CurrentAcountController::saldoInicial()` (recibos y reportes de caja).
	 *
	 * @param float $saldo_importado Saldo indicado en el Excel (negativo = a favor).
	 * @param \App\Models\CreditAccount $credit_account Cuenta corriente en pesos.
	 * @param string $model_name Nombre del modelo (`client` o `provider`).
	 * @param mixed $model Instancia persistida del cliente/proveedor.
	 * @return bool true si cargó el saldo inicial; false si la cuenta ya tenía movimientos.
	 *              procesarSaldoImportacion() lo ignora; setSaldoInicial() lo usa para avisar.
	 */
	static function crearSaldoInicialPorImportacion($saldo_importado, $credit_account, $model_name, $model) {
		$tiene_movimientos = CurrentAcount::where('credit_account_id', $credit_account->id)->exists();

		if ($tiene_movimientos) {
			return false;
		}

		$is_for_debe = $saldo_importado >= 0;

		CurrentAcount::create([
			'detalle'           => 'Saldo inicial',
			'status'            => $is_for_debe ? 'sin_pagar' : 'pago_from_client',
			'client_id'         => $model_name == 'client' ? $model->id : null,
			'provider_id'       => $model_name == 'provider' ? $model->id : null,
			'debe'              => $is_for_debe ? $saldo_importado : null,
			'haber'             => !$is_for_debe ? abs($saldo_importado) : null,
			'credit_account_id' => $credit_account->id,
			'moneda_id'         => 1,
			'saldo'             => $saldo_importado,
		]);

		$model->saldo_pesos = $saldo_importado;
		$model->save();

		$credit_account->saldo = $saldo_importado;
		$credit_account->save();

		return true;
	}

	/**
	 * Ajusta el saldo de un cliente/proveedor existente creando nota de crédito o débito.
	 *
	 * @param float $saldo_importado Saldo objetivo indicado en el Excel.
	 * @param \App\Models\CreditAccount $credit_account Cuenta corriente en pesos.
	 * @param string $model_name Nombre del modelo (`client` o `provider`).
	 * @param int $model_id ID del registro.
	 */
	static function ajustarSaldoPorImportacion($saldo_importado, $credit_account, $model_name, $model_id) {
		CurrentAcountHelper::update_credit_account_saldo($credit_account->id);
		$credit_account->refresh();

		$saldo_actual = (float) $credit_account->saldo;
		$diferencia = $saldo_importado - $saldo_actual;

		if (abs($diferencia) < 0.009) {
			return;
		}

		$observacion = 'Ajuste por importacion de Excel para actualizar el saldo.';

		if ($diferencia < 0) {
			$monto_nota_credito = abs($diferencia);

			CurrentAcountHelper::notaCredito(
				$credit_account->id,
				$monto_nota_credito,
				$observacion,
				$model_name,
				$model_id
			);

			return;
		}

		$nota_debito = CurrentAcount::create([
			'detalle'           => 'Nota de debito',
			'description'       => $observacion,
			'debe'              => $diferencia,
			'status'            => 'sin_pagar',
			'client_id'         => $model_name == 'client' ? $model_id : null,
			'provider_id'       => $model_name == 'provider' ? $model_id : null,
			'user_id'           => UserHelper::userId(),
			'credit_account_id' => $credit_account->id,
			'moneda_id'         => 1,
		]);

		$nota_debito->saldo = CurrentAcountHelper::getSaldo($credit_account->id, $nota_debito) + $diferencia;
		$nota_debito->save();

		CurrentAcountHelper::checkCurrentAcountSaldo($credit_account->id);
		CurrentAcountHelper::update_credit_account_saldo($credit_account->id);
	}

	/**
	 * Carga el saldo del Excel como SALDO INICIAL del cliente/proveedor, si su cuenta en pesos está
	 * vacía. Es el helper de la importación de PROVEEDORES (`ProviderImport`).
	 *
	 * La diferencia con procesarSaldoImportacion() (clientes) es deliberada: acá el saldo del Excel
	 * es siempre un saldo INICIAL y nunca ajusta una cuenta que ya tiene movimientos (es lo que dice
	 * la pantalla de importación). Clientes, en cambio, ajusta al existente con una nota de crédito
	 * o de débito. Para que ese "no se cargó" no sea silencioso, la función devuelve un estado y
	 * ProviderImport lo avisa al final de la importación.
	 *
	 * 🔴 Solo se avisa lo que hace falta avisar: si la cuenta ya tiene movimientos pero su saldo YA
	 * ES el del Excel (diferencia menor a un centavo), el estado es 'sin_cambios' y no se avisa
	 * nada. Es la segunda pasada del mismo archivo (la doble pasada del motor de /implementar), o un
	 * proveedor repetido en el Excel con el mismo saldo: un "no se cargó, ajustá con una nota" ahí
	 * es falso, y quien lo sigue duplica la deuda. La comparación es contra `credit_accounts.saldo`
	 * de la cuenta en pesos, el saldo vivo.
	 *
	 * 🔴 Si el modelo no tiene cuenta en pesos, la CREA (las dos monedas) y carga el saldo. Antes
	 * salía con un `return`: como ProviderImport creaba el proveedor sin cuentas, el saldo de todo
	 * proveedor NUEVO se perdía en silencio (misión importacion-proveedores-saldo-inicial,
	 * 8/10/2026). Ahora ProviderImport ya le crea las cuentas al crearlo; esto cubre además al
	 * proveedor viejo que quedó sin cuenta, que al reimportar el Excel recibe la cuenta y el saldo
	 * (es la reparación de lo ya importado).
	 *
	 * Un saldo 0 (menos de medio centavo) no carga nada: es 'sin_saldo'. La cuenta igual se crea si
	 * faltaba. Antes cargaba un "Saldo inicial" de $0, la cuenta quedaba "con movimientos" y el
	 * botón "Saldo inicial", que no acepta 0, ya no se podía usar (422).
	 *
	 * La cuenta lleva el `user_id` del propio modelo: así queda siempre del mismo comercio que el
	 * cliente/proveedor, venga de donde venga la llamada. (Sin el parámetro, crear_credit_accounts()
	 * lo sacaría de UserHelper::userId().)
	 *
	 * El saldo se lee con leerSaldoDeLaFila(), igual que en clientes: una celda NUMÉRICA entra tal
	 * cual y una de TEXTO se lee como los artículos. Antes era un `(float)` del string:
	 * "$ 52.000,50" daba 0 (y no se cargaba nada) y "52.000" daba 52.
	 *
	 * 🔴 Un saldo ILEGIBLE ("s/d", "-", una fórmula) devuelve 'ilegible' ANTES de crear la cuenta
	 * que falte, igual que una celda vacía: no se carga nada y no se lo trata como 0. ProviderImport
	 * lo avisa al final con el texto de la celda (misión importacion-saldo-celdas-de-texto,
	 * 9/10/2026).
	 *
	 * @param mixed $row Fila del Excel.
	 * @param array $columns Mapeo de columnas de la importación.
	 * @param string $model_name Nombre del modelo (`client` o `provider`).
	 * @param mixed $model Instancia persistida del cliente/proveedor, o null.
	 * @param array|null $saldos SALIDA, opcional: cuando la cuenta ya tenía movimientos (estados
	 *                           'sin_cambios' y 'ya_tenia_movimientos') queda con
	 *                           ['excel' => saldo del Excel, 'cuenta' => saldo de la cuenta], para
	 *                           que el llamador pueda mostrar los dos montos en el aviso. Con el
	 *                           estado 'ilegible' queda con ['texto' => lo que traía la celda].
	 * @return string 'sin_saldo'            -> la fila no trae saldo, el saldo es 0, o no hay modelo
	 *                                          (una fila de "solo editar" cuyo proveedor no existe):
	 *                                          nada que cargar.
	 *                'ilegible'             -> la celda trae algo que no es un número: no se cargó
	 *                                          nada (se avisa).
	 *                'cargado'              -> se cargó el saldo inicial.
	 *                'sin_cambios'          -> la cuenta ya tenía movimientos y su saldo ya es el del
	 *                                          Excel: no se carga nada y no hay nada que avisar.
	 *                'ya_tenia_movimientos' -> la cuenta ya tenía movimientos y su saldo es OTRO: el
	 *                                          saldo del Excel NO se cargó (se avisa).
	 */
	static function setSaldoInicial($row, $columns, $model_name, $model, &$saldos = null) {

		/*
		 * Sin modelo no hay dónde cargar nada. Antes esto era `$model->id` sobre null: un
		 * ErrorException que tiraba la importación entera con un 500 a mitad del archivo.
		 */
		if (is_null($model)) {
			return 'sin_saldo';
		}

		$lectura = self::leerSaldoDeLaFila($row, $columns);

		if ($lectura['estado'] == 'vacio') {
			return 'sin_saldo';
		}

		if ($lectura['estado'] == 'ilegible') {
			$saldos = [
				'texto' => $lectura['texto'],
			];

			return 'ilegible';
		}

		$saldo_importado = $lectura['valor'];

		$credit_account = self::get_credit_account_pesos($model_name, $model->id);

		if (is_null($credit_account)) {
			CreditAccountHelper::crear_credit_accounts($model_name, $model->id, $model->user_id);
			$credit_account = self::get_credit_account_pesos($model_name, $model->id);
		}

		if (abs($saldo_importado) < 0.005) {
			return 'sin_saldo';
		}

		if (self::crearSaldoInicialPorImportacion($saldo_importado, $credit_account, $model_name, $model)) {
			return 'cargado';
		}

		$saldo_de_la_cuenta = (float) $credit_account->saldo;

		$saldos = [
			'excel'  => $saldo_importado,
			'cuenta' => $saldo_de_la_cuenta,
		];

		if (abs($saldo_importado - $saldo_de_la_cuenta) < 0.01) {
			return 'sin_cambios';
		}

		return 'ya_tenia_movimientos';
	}

	// static function setSaldoInicial($row, $columns, $model_name, $model) {

	// 	$saldo_actual = ImportHelper::getColumnValue($row, 'saldo_actual', $columns);
        
    //     if (!is_null($saldo_actual)) {

    //         $current_acounts = CurrentAcount::where($model_name.'_id', $model->id)
    //                                         ->get();

    //         if (count($current_acounts) == 0) {
            	
    //             $is_for_debe = false;
    //             $saldo_inicial = (float)$saldo_actual;
    //             if ($saldo_inicial >= 0) {
    //                 $is_for_debe = true;
    //             }
    //             $current_acount = CurrentAcount::create([
    //                 'detalle'   => 'Saldo inicial',
    //                 'status'    => $is_for_debe ? 'sin_pagar' : 'pago_from_client',
    //                 'client_id' => $model_name == 'client' ? $model->id : null,
    //                 'provider_id' => $model_name == 'provider' ? $model->id : null,
    //                 'debe'      => $is_for_debe ? $saldo_inicial : null,
    //                 'haber'     => !$is_for_debe ? $saldo_inicial : null,
    //                 'saldo'     => $saldo_inicial,
    //             ]);
    //             $model->saldo = $saldo_inicial;
    //             $model->save();
    //         }
    //     }
	// }

	static function get_bran_id($brand_excel, $ct, $owner) {
		if ($brand_excel != '') {
			
			$brand = Brand::where('user_id', $owner->id)
								->where('name', $brand_excel)
								->first();

			if (is_null($brand)) {
				$brand = Brand::create([
	        		// 'num'		=> $ct->num('categories', $owner->id, 'user_id', $owner->id),
					'name' 		=> $brand_excel,
					'user_id' 	=> $owner->id,
				]);
			}
			return $brand->id;
		}
		return null;
	}

	static function getCategoryId($categoria, $ct, $owner) {
		if ($categoria != '') {
			$category = Category::where('user_id', $owner->id)
								->where('name', $categoria)
								->first();
			if (is_null($category)) {
				$category = Category::create([
	        		'num'		=> $ct->num('categories', $owner->id, 'user_id', $owner->id),
					'name' 		=> $categoria,
					'user_id' 	=> $owner->id,
				]);


				SetPriceTypesHelper::set_price_types($category, $owner);
				SetPriceTypesHelper::set_rangos($category, $owner);
				
			}
			return $category->id;
		}
		return null;
	}

	static function getSubcategoryId($categoria, $sub_categoria, $ct, $owner) {
		if ($categoria != '' && $sub_categoria != '') {
			$category = Category::where('user_id', $owner->id)
								->where('name', $categoria)
								->first();
			
			$sub_category = SubCategory::where('user_id', $owner->id)
										->where('name', $sub_categoria)
										->where('category_id', $category->id)
										->first();
			if (is_null($sub_category)) {
				$sub_category = SubCategory::create([
	        		'num'			=> $ct->num('sub_categories', $owner->id, 'user_id', $owner->id),
					'name' 			=> $sub_categoria,
					'category_id' 	=> $category->id,
					'user_id'		=> $owner->id,
				]);

				if (UserHelper::hasExtencion('lista_de_precios_por_categoria', $owner)) {

					SetPriceTypesHelper::set_price_types($sub_category, $owner);
				}
			}
			return $sub_category->id;
		}
		return null;
	}

	static function saveLocation($localidad, $ct) {
		if (!is_null($localidad) && $localidad != 'Sin especificar') {
	        $data = [
                'name'      => $localidad,
                'user_id'   => $ct->userId(),
            ];
	        $ct->createIfNotExist('locations', 'name', $localidad, $data);
	    }
	}

	/**
	 * Busca o crea una provincia por nombre para el usuario actual de la importación.
	 *
	 * @param string|null $provincia_name Nombre de la provincia indicado en el Excel.
	 * @param \App\Http\Controllers\Controller $ct Controlador auxiliar con userId y helpers de persistencia.
	 * @return int|null ID de la provincia creada o existente; null si el valor no es usable.
	 */
	static function saveProvincia($provincia_name, $ct) {
		// Valores vacíos o genéricos no generan provincia.
		if (is_null($provincia_name) || $provincia_name === '' || $provincia_name === 'Sin especificar') {
			return null;
		}

		// Datos mínimos para crear la provincia si aún no existe para este usuario.
		$data = [
			'name'    => $provincia_name,
			'user_id' => $ct->userId(),
		];

		$ct->createIfNotExist('provincias', 'name', $provincia_name, $data);

		return $ct->getModelBy('provincias', 'name', $provincia_name, true, 'id');
	}

	/**
	 * Busca o crea una localidad asociada a una provincia concreta.
	 * Permite homónimos (p. ej. Paraná en Entre Ríos vs Paraná en Santa Fe).
	 *
	 * @param string|null $localidad Nombre de la localidad indicado en el Excel.
	 * @param int|null $provincia_id Provincia a la que pertenece la localidad.
	 * @param \App\Http\Controllers\Controller $ct Controlador auxiliar con userId y correlativos.
	 * @return int|null ID de la localidad creada o existente; null si el valor no es usable.
	 */
	static function saveLocationWithProvincia($localidad, $provincia_id, $ct) {
		// Sin nombre de localidad no hay nada que persistir.
		if (is_null($localidad) || $localidad === '' || $localidad === 'Sin especificar') {
			return null;
		}

		$user_id = $ct->userId();

		// La unicidad en importación es nombre + provincia_id + usuario.
		$existing_location = DB::table('locations')
			->where('name', $localidad)
			->where('user_id', $user_id)
			->where('provincia_id', $provincia_id)
			->first();

		// Si no existe la combinación, se crea una localidad nueva bajo esa provincia.
		if (is_null($existing_location)) {
			DB::table('locations')->insert([
				'num'          => $ct->num('locations'),
				'name'         => $localidad,
				'provincia_id' => $provincia_id,
				'user_id'      => $user_id,
			]);
		}

		return self::getLocationIdByNameAndProvincia($localidad, $provincia_id, $ct);
	}

	/**
	 * Obtiene el ID de una localidad filtrando por nombre, provincia y usuario.
	 *
	 * @param string $localidad Nombre de la localidad.
	 * @param int|null $provincia_id Provincia asociada.
	 * @param \App\Http\Controllers\Controller $ct Controlador auxiliar con userId.
	 * @return int|null ID encontrado o null si no existe.
	 */
	static function getLocationIdByNameAndProvincia($localidad, $provincia_id, $ct) {
		$location = DB::table('locations')
			->where('name', $localidad)
			->where('user_id', $ct->userId())
			->where('provincia_id', $provincia_id)
			->first();

		if (is_null($location)) {
			return null;
		}

		return $location->id;
	}

	static function saveSeller($seller, $ct) {
		if (!is_null($seller) && $seller != 'Sin especificar') {
	        $data = [
	        	'num'		=> $ct->num('sellers'),
                'name'      => $seller,
                'user_id'   => $ct->userId(),
            ];
	        $ct->createIfNotExist('sellers', 'name', $seller, $data);
	    }
	}

	static function saveProvider($proveedor, $ct, $owner) {
		if ($proveedor != 'Sin especificar' && $proveedor != '') {
	        $data = [
	        	'num'		=> $ct->num('providers', $owner->id, 'user_id', $owner->id),
                'name'      => $proveedor,
                'user_id'   => $owner->id,
            ];
	        $ct->createIfNotExist('providers', 'name', $proveedor, $data, true, $owner->id);
	    }
	}

	static function savePriceType($tipo_de_precio, $ct) {
		if ($tipo_de_precio != 'Sin especificar' && $tipo_de_precio != '') {
	        $data = [
                'name'      => $tipo_de_precio,
                'user_id'   => $ct->userId(),
            ];
	        $ct->createIfNotExist('price_types', 'name', $tipo_de_precio, $data);
	    }
	}

	/**
	 * Crea SIEMPRE una relación de la importación de clientes/proveedores (provincia, localidad,
	 * vendedor o lista de precios) con ese nombre, y devuelve su id. Es la decisión "crear uno
	 * nuevo" del paso 3 (misión importacion-relaciones-parecidas, 8/10/2026).
	 *
	 * 🔴 No pasa por createIfNotExist() a propósito: ese busca con un WHERE de MySQL, cuya
	 * collation ignora mayúsculas y tildes, así que para "Juan Perez" encontraría "JUAN PEREZ" y no
	 * crearía nada — justo lo contrario de lo que el usuario eligió. Quien llama ya buscó el nombre
	 * EXACTO (comparación binaria) antes de llegar acá.
	 *
	 * Inserta los mismos campos que los save*() de arriba: `num` en vendedor y en localidad con
	 * provincia, `provincia_id` en localidad.
	 *
	 * @param string $tipo provincia | localidad | vendedor | tipo_de_precio
	 * @param string $nombre
	 * @param \App\Http\Controllers\Controller $ct
	 * @param int|null $provincia_id Solo para localidad.
	 * @return int|null Id creado; null si el tipo no se conoce.
	 */
	static function crearRelacionDeImportacion($tipo, $nombre, $ct, $provincia_id = null) {
		$user_id = $ct->userId();

		if ($tipo === 'provincia') {
			return DB::table('provincias')->insertGetId([
				'name'    => $nombre,
				'user_id' => $user_id,
			]);
		}

		if ($tipo === 'localidad') {
			if (!is_null($provincia_id)) {
				return DB::table('locations')->insertGetId([
					'num'          => $ct->num('locations'),
					'name'         => $nombre,
					'provincia_id' => $provincia_id,
					'user_id'      => $user_id,
				]);
			}

			return DB::table('locations')->insertGetId([
				'name'    => $nombre,
				'user_id' => $user_id,
			]);
		}

		if ($tipo === 'vendedor') {
			return DB::table('sellers')->insertGetId([
				'num'     => $ct->num('sellers'),
				'name'    => $nombre,
				'user_id' => $user_id,
			]);
		}

		if ($tipo === 'tipo_de_precio') {
			return DB::table('price_types')->insertGetId([
				'name'    => $nombre,
				'user_id' => $user_id,
			]);
		}

		return null;
	}

	/**
	 * Resuelve el ID de condición frente al IVA tolerando mayúsculas/minúsculas y alias comunes.
	 *
	 * @param string|null $iva_condition_name Texto indicado en el Excel.
	 * @return int|null ID de iva_conditions o null si no se pudo resolver.
	 */
	static function getIvaConditionId($iva_condition_name) {
		if (is_null($iva_condition_name) || trim($iva_condition_name) === '') {
			return null;
		}

		$iva_condition_name = trim($iva_condition_name);

		$iva_condition = DB::table('iva_conditions')
			->where('name', $iva_condition_name)
			->first();

		if (is_null($iva_condition)) {
			$iva_condition = DB::table('iva_conditions')
				->whereRaw('LOWER(name) = ?', [mb_strtolower($iva_condition_name, 'UTF-8')])
				->first();
		}

		if (is_null($iva_condition)) {
			$aliases = [
				'monotributo'           => 'Monotributista',
				'monotributista'        => 'Monotributista',
				'responsable inscripto' => 'Responsable inscripto',
				'ri'                    => 'Responsable inscripto',
				'consumidor final'      => 'Consumidor final',
				'cf'                    => 'Consumidor final',
				'exento'                => 'Exento',
			];

			$normalized_name = mb_strtolower($iva_condition_name, 'UTF-8');

			if (isset($aliases[$normalized_name])) {
				$iva_condition = DB::table('iva_conditions')
					->where('name', $aliases[$normalized_name])
					->first();
			}
		}

		if (is_null($iva_condition)) {
			return null;
		}

		return $iva_condition->id;
	}

	/**
	 * El porcentaje de IVA de una celda, como se guarda en la tabla de IVAs: sin «%», sin espacios alrededor y con
	 * punto decimal. «10,5», «10,5%» y « 10,5 % » son «10.5»; «21%» es «21»; «Exento» queda «Exento». La usan los
	 * dos motores de artículos (ProcessRow::get_iva_id() y getIvaId(), el de ArticleImport) para que ninguno cree
	 * un IVA nuevo «10,5» que no es ninguno de los de AFIP (misión prueba-e2e-campana-imperio, ronda 6, E8.1).
	 *
	 * @param  mixed  $iva
	 * @return string  '' si la celda no trae nada
	 */
	static function normalizar_porcentaje_de_iva($iva) {
		$texto = trim(str_replace('%', '', (string) $iva));

		return preg_replace('/^(\d+),(\d+)$/', '$1.$2', $texto);
	}

	static function getIvaId($iva, $article = null) {
		if (!is_null($iva)) {

			$iva = self::normalizar_porcentaje_de_iva($iva);
			// Vacío (una celda con solo «%» o espacios): el de siempre. Antes `'' == 0` creaba un IVA en blanco.
			if ($iva !== '') {
				$_iva = Iva::where('percentage', $iva)
							->first();
				if (is_null($_iva)) {
					$_iva = Iva::create([
						'percentage' => $iva,
					]);
				}
				return $_iva->id;
			}
		} else if (!is_null($article)) {
			if (!is_null($article->iva_id)) {
				return $article->iva_id;
			}
		}
		return 2;
	}
	
}