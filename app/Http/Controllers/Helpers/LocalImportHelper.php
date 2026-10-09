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

class LocalImportHelper {

	/**
	 * Tope de filas que nombra el aviso de saldos ilegibles (bloque_de_saldos_ilegibles()). Del
	 * resto se dice cuántas son. El evento de la notificación de fin viaja por Pusher, que corta en
	 * 10 KB: sin tope, un archivo con cientos de saldos ilegibles perdería la notificación ENTERA,
	 * botón incluido. Mismo criterio que ProviderImport::MAXIMO_DE_PROVEEDORES_EN_EL_AVISO.
	 *
	 * 🔴 Es 20 y no más por una medición, no por gusto: en proveedores este bloque convive con el de
	 * "Saldos del Excel que no se cargaron" (hasta 50 nombres). Con los dos al tope, nombres largos
	 * y textos largos, el evento pesó 10.056 bytes con 30 filas, 9.401 con 25 y 8.747 con 20 (9/10/2026;
	 * el umbral del test es 9.000). Subirlo vuelve a dejar sin notificación una importación grande.
	 *
	 * @var int
	 */
	const MAXIMO_DE_FILAS_EN_EL_AVISO_DE_SALDOS_ILEGIBLES = 20;

	/**
	 * Largo máximo, en caracteres, del nombre y del texto de la celda en cada párrafo del aviso de
	 * saldos ilegibles. Lo que pasa se corta con "…". También es por el límite de Pusher.
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
	 * Arma el bloque "Saldos del Excel que no se pudieron leer" de la notificación de fin de
	 * importación (formato `title` + `parrafos`, el que el SPA ya renderiza). Lo usan ClientImport y
	 * ProviderImport, cada uno con las filas que juntó.
	 *
	 * Un párrafo por fila: `Fila 7, Juan Pérez: "s/d"`. Comillas RECTAS a propósito: Pusher escapa
	 * cada caracter no ASCII a `\uXXXX` (seis bytes) y el evento tiene un tope de 10 KB. Por lo mismo
	 * el nombre se corta a LARGO_MAXIMO_DEL_NOMBRE_EN_EL_AVISO caracteres y el texto a
	 * LARGO_MAXIMO_DEL_TEXTO_EN_EL_AVISO, y se nombran como mucho
	 * MAXIMO_DE_FILAS_EN_EL_AVISO_DE_SALDOS_ILEGIBLES filas; si hay más, "y N filas más". La
	 * explicación, en el idioma del comerciante, va al final.
	 *
	 * @param array $saldos_ilegibles Filas como [['fila' => int, 'nombre' => string, 'texto' => string], ...],
	 *                                en el orden del Excel.
	 * @return array|null El bloque, o null si no hubo ninguna fila ilegible (no se avisa nada).
	 */
	static function bloque_de_saldos_ilegibles($saldos_ilegibles) {
		if (count($saldos_ilegibles) == 0) {
			return null;
		}

		$parrafos = [];

		foreach (array_slice($saldos_ilegibles, 0, self::MAXIMO_DE_FILAS_EN_EL_AVISO_DE_SALDOS_ILEGIBLES) as $ilegible) {
			$parrafos[] = 'Fila ' . $ilegible['fila']
				. ', ' . self::recortar_para_el_aviso($ilegible['nombre'], self::LARGO_MAXIMO_DEL_NOMBRE_EN_EL_AVISO)
				. ': "' . self::recortar_para_el_aviso($ilegible['texto'], self::LARGO_MAXIMO_DEL_TEXTO_EN_EL_AVISO) . '"';
		}

		$restantes = count($saldos_ilegibles) - self::MAXIMO_DE_FILAS_EN_EL_AVISO_DE_SALDOS_ILEGIBLES;

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
	 * Corta un texto a $maximo caracteres (no bytes) y le agrega "…" si lo cortó.
	 *
	 * @param string|null $texto
	 * @param int $maximo
	 * @return string
	 */
	private static function recortar_para_el_aviso($texto, $maximo) {
		$texto = (string) $texto;

		if (mb_strlen($texto, 'UTF-8') <= $maximo) {
			return $texto;
		}

		return mb_substr($texto, 0, $maximo, 'UTF-8') . '…';
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

	static function getIvaId($iva, $article = null) {
		if (!is_null($iva)) {

			$iva = str_replace('%', '', $iva);
			if ($iva != '' || $iva == '0' || $iva == 0) {
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