<?php

namespace App\Http\Controllers\Helpers\caja;

use App\Http\Controllers\CommonLaravel\Helpers\Numbers;
use App\Http\Controllers\Helpers\UserHelper;
use App\Models\AperturaCaja;
use App\Models\Caja;
use App\Models\Expense;
use App\Models\MovimientoCaja;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MovimientoCajaHelper {

	/** Valida y bloquea las cajas de una operación nueva hasta el commit del llamador. */
	static function validar_cajas_para_operacion($caja_ids) {
		$ids = array_unique(array_filter(array_map('intval', $caja_ids)));
		sort($ids, SORT_NUMERIC);
		foreach ($ids as $id) {
			$caja = Caja::where('id', $id)->lockForUpdate()->first();
			$apertura = $caja ? AperturaCaja::where('id', $caja->current_apertura_caja_id)
				->where('caja_id', $id)->lockForUpdate()->first() : null;
			if (!$caja || (int) $caja->abierta !== 1 || !$apertura || !is_null($apertura->cerrada_at)) {
				$nombre = $caja ? $caja->name : ('N° '.$id);
				self::rechazar_operacion('La caja '.$nombre.' está cerrada o no tiene una apertura vigente. Abrila antes de registrar la operación.');
			}
		}
	}

	static function rechazar_operacion($mensaje, $campo = 'caja_id') {
		$errores = [$campo => [$mensaje]];
		$excepcion = \Illuminate\Validation\ValidationException::withMessages($errores);
		// El Handler de la aplicación reemplaza el mensaje nativo por uno genérico.
		$excepcion->response = response()->json(['message' => $mensaje, 'errors' => $errores], 422);
		throw $excepcion;
	}

	/** Solo las filas que el alta adjunta pueden generar un movimiento de caja. */
	static function cajas_del_payload($filas) {
		$ids = [];
		foreach (is_array($filas) ? $filas : [] as $fila) {
			if (isset($fila['amount']) && isset($fila['caja_id'])
				&& isset($fila['current_acount_payment_method_id'])
				&& \App\Models\CurrentAcountPaymentMethod::where('id', $fila['current_acount_payment_method_id'])->exists()) {
				$ids[] = $fila['caja_id'];
			}
		}
		return $ids;
	}

	function crear_movimiento($data) {
		return DB::transaction(function () use ($data) {
			// Los llamadores históricos que indican una apertura conservan su referencia.
			// Una operación nueva sin apertura explícita solo puede usar el turno vigente.
			if (empty($data['apertura_caja_id'])) {
				self::validar_cajas_para_operacion([$data['caja_id']]);
			} else {
				Caja::where('id', $data['caja_id'])->lockForUpdate()->first();
			}
			return $this->persistir_movimiento($data);
		});
	}

	/** Se ejecuta con la caja bloqueada y la transacción de crear_movimiento abierta. */
	protected function persistir_movimiento($data) {

		$this->caja_id = $data['caja_id'];

		$apertura_caja_id = $this->get_apertura_caja_id($data);
		$employee_id = UserHelper::userId(false);

		/*
		 * Grupo 223 · Prompt 02 — liquidación y comisión.
		 * SOLO se calcula si el llamador manda el flag explícito `aplica_liquidacion` en true,
		 * junto con el método de pago y un ingreso positivo. Está PROHIBIDO inferir esto de
		 * 'ingreso > 0' o de cualquier otra cosa: crear_movimiento() la comparten 6 flujos
		 * distintos y hay ingresos que no son cobros (reversión de gasto/pago a proveedor,
		 * destino de una transferencia entre cajas) que no deben calcular comisión.
		 */
		$aplica_liquidacion = !empty($data['aplica_liquidacion'])
			&& isset($data['current_acount_payment_method_id'])
			&& !is_null($data['current_acount_payment_method_id'])
			&& isset($data['ingreso'])
			&& !is_null($data['ingreso'])
			&& $data['ingreso'] > 0;

		// Campos calculados de liquidación/comisión a persistir en el movimiento (null por default:
		// un movimiento que no aplica liquidación queda exactamente como hoy).
		$fecha_liquidacion_estimada = null;
		$monto_neto_estimado = null;
		$comision_calculada = null;

		$caja_liquidacion = null;

		if ($aplica_liquidacion) {

			$caja_liquidacion = Caja::find($data['caja_id']);

			// Prompt 380/01: ademas de las tres condiciones de arriba, la caja tiene que tener
			// REGIMEN DE LIQUIDACION configurado para este metodo de pago (dias_liquidacion o
			// comision_porcentaje cargados, cualquiera de los dos, incluso en 0 explicito -- ver
			// CajaLiquidacionHelper::tiene_configuracion()). Sin esto, una caja de efectivo sin
			// configurar entraba igual por las tres condiciones de arriba, y
			// CajaLiquidacionHelper::calcular() -- helper puro, siempre devuelve numeros -- persistia
			// comision_calculada = '0.00' en vez de null, rompiendo el criterio "null = no aplica,
			// 0 = aplica y da cero" que necesita el reporte de Flujo de Caja para distinguir los dos
			// casos. calcular() NO se toca: la decision de si corresponde calcular se agrega aca.
			$aplica_liquidacion = CajaLiquidacionHelper::tiene_configuracion($caja_liquidacion, $data['current_acount_payment_method_id']);
		}

		if ($aplica_liquidacion) {

			$calculo = CajaLiquidacionHelper::calcular(
				$caja_liquidacion,
				$data['current_acount_payment_method_id'],
				$data['ingreso'],
				Carbon::now()
			);

			$fecha_liquidacion_estimada = $calculo['fecha_liquidacion_estimada']->format('Y-m-d');
			$monto_neto_estimado = $calculo['monto_neto_estimado'];
			$comision_calculada = $calculo['comision_calculada'];
		}

		// El movimiento y el gasto automático de comisión (si corresponde) se crean en la misma
		// transacción: si falla el gasto, se revierte todo (regla dura del prompt 02).
		$this->movimiento_caja = DB::transaction(function () use (
			$data,
			$apertura_caja_id,
			$employee_id,
			$fecha_liquidacion_estimada,
			$monto_neto_estimado,
			$comision_calculada,
			$aplica_liquidacion
		) {

			$campos = [
	            'concepto_movimiento_caja_id'	=> $data['concepto_movimiento_caja_id'],

	            'ingreso'						=> $data['ingreso'],
	            'egreso'						=> $data['egreso'],

	            'notas'							=> $data['notas'],

	            'employee_id'					=> $employee_id,
	            'apertura_caja_id'				=> $apertura_caja_id,

	            'sale_id'						=> isset($data['sale_id']) ? $data['sale_id'] : null,
	            'expense_id'					=> isset($data['expense_id']) ? $data['expense_id'] : null,

	            'caja_id'						=> $data['caja_id'],

	            'fecha_liquidacion_estimada'	=> $fecha_liquidacion_estimada,
	            'monto_neto_estimado'			=> $monto_neto_estimado,
	            'comision_calculada'			=> $comision_calculada,
			];

			/*
			 * Misión movimientos-caja-manuales (9/10/2026): la marca `manual`. SOLO
			 * MovimientoCajaController::store() (el alta desde Tesorería → Movimientos) manda
			 * `manual` en true; todo otro llamador (venta, gasto, pago, transferencia,
			 * compensación, pago a vendedor) no lo manda y queda en false. Así se sabe qué
			 * movimiento se puede corregir o eliminar desde la caja (MovimientoCaja::origen_automatico()).
			 *
			 * 🔴 Solo si la columna ya existe (MovimientoCaja::hay_columna_manual()): en la ventana
			 * del deploy el código llega antes que la migración, y nombrar una columna que no
			 * existe tumbaría toda venta, gasto o pago que mueva caja.
			 */
			if (MovimientoCaja::hay_columna_manual()) {
				$campos['manual'] = !empty($data['manual']);
			}

			$movimiento_caja = MovimientoCaja::create($campos);

			if ($aplica_liquidacion && $comision_calculada > 0) {

				Self::crear_gasto_comision($movimiento_caja, $data['caja_id'], $data['current_acount_payment_method_id'], $comision_calculada);
			}

			return $movimiento_caja;
		});


		$this->set_saldos();

		Self::set_apertura_caja_ingresos_egresos($this->movimiento_caja->apertura_caja_id);

		return $this->movimiento_caja;

	}

	/**
	 * Crea el gasto automático de comisión para un movimiento de ingreso liquidable, con IVA
	 * discriminado según la configuración resuelta (cascada caja + override por método de pago).
	 *
	 * Reglas duras (Grupo 223 · Prompt 02):
	 * - El `Expense` se crea directo con `Expense::create()`, NUNCA vía
	 *   `ExpenseCajaHelper::guardar_movimiento_caja()`: ese helper genera OTRO movimiento de
	 *   egreso en la caja, y la comisión ya está reflejada en `monto_neto_estimado` — contarla
	 *   dos veces descuadraría el saldo contable.
	 * - `caja_id` del gasto queda en null a propósito, por el mismo motivo de arriba.
	 * - Idempotente: si el movimiento ya tiene `comision_expense_id`, no crea un segundo gasto.
	 * - Si no hay `expense_concept_id` resuelto, no se crea el gasto pero se loguea un warning:
	 *   la comisión calculada sigue siendo información válida aunque falte esa config.
	 *
	 * @param \App\Models\MovimientoCaja $movimiento_caja Movimiento recién creado (ya persistido).
	 * @param int $caja_id Caja de la que sale la comisión (para resolver moneda y owner).
	 * @param int $current_acount_payment_method_id Método de pago del cobro, para la cascada de config.
	 * @param float $comision_calculada Comisión bruta ya calculada por `CajaLiquidacionHelper::calcular()`.
	 * @return void
	 */
	static function crear_gasto_comision($movimiento_caja, $caja_id, $current_acount_payment_method_id, $comision_calculada) {

		// Idempotencia: un retry sobre el mismo movimiento no puede duplicar el Expense.
		if (!is_null($movimiento_caja->comision_expense_id)) {
			return;
		}

		$caja = Caja::find($caja_id);

		// Configuración efectiva (cascada caja + override) para saber concepto, alícuota e IVA incluido.
		$config = CajaLiquidacionHelper::resolve_config($caja, $current_acount_payment_method_id);

		if (is_null($config['expense_concept_id'])) {

			Log::warning('CajaLiquidacionHelper: no se creó el gasto automático de comisión del movimiento de caja N° '.$movimiento_caja->id.' (caja '.$caja_id.') porque no hay expense_concept_id configurado.');
			return;
		}

		$importe_iva = CajaLiquidacionHelper::calcular_iva_comision($comision_calculada, $config['comision_iva_alicuota'], $config['comision_iva_incluido']);

		// Con IVA incluido, la comisión ya ES el total. Neta de IVA, el total a pagar es comisión + iva.
		$amount = $comision_calculada;
		if (!$config['comision_iva_incluido']) {
			$amount = Numbers::redondear($comision_calculada + $importe_iva);
		}

		$num = Self::num_expense_owner($caja->user_id);

		$expense = Expense::create([
			'num'					=> $num,
			'expense_concept_id'	=> $config['expense_concept_id'],
			'amount'				=> $amount,
			'importe_iva'			=> $importe_iva,
			'moneda_id'				=> $caja->moneda_id,
			'observations'			=> 'Comisión automática — movimiento de caja N° '.$movimiento_caja->id.' ('.$caja->name.')',
			'user_id'				=> $caja->user_id,
			'caja_id'				=> null,
		]);

		$movimiento_caja->comision_expense_id = $expense->id;
		$movimiento_caja->save();
	}

	/**
	 * Replica la lógica de `Controller::num()` para resolver el correlativo de `expenses` por
	 * owner, sin instanciar el Controller desde un helper (regla del prompt 02).
	 *
	 * @param int $user_id Owner dueño del correlativo.
	 * @return int Próximo número correlativo.
	 */
	static function num_expense_owner($user_id) {

		$resolver = function () use ($user_id) {

			// Lock sobre la fila de users para serializar lecturas concurrentes del máximo.
			DB::table('users')->where('id', $user_id)->lockForUpdate()->first();

			$last = DB::table('expenses')
						->where('user_id', $user_id)
						->orderBy('num', 'DESC')
						->lockForUpdate()
						->first();

			if (is_null($last) || is_null($last->num)) {
				return 1;
			}

			return $last->num + 1;
		};

		// Si ya hay una transacción abierta (crear_movimiento() la abre), no anidamos otra.
		if (DB::transactionLevel() > 0) {
			return $resolver();
		}

		return DB::transaction($resolver);
	}

	function get_apertura_caja_id($data) {

		if (isset($data['apertura_caja_id'])) {

			return $data['apertura_caja_id'];
		}

		$current_aperutra_caja_id = $this->get_current_aperutra_caja();

		return $current_aperutra_caja_id;
	}

	/** La apertura vigente real: puede ser una reapertura anterior a la última por fecha. */
	function get_current_aperutra_caja() {
		self::validar_cajas_para_operacion([$this->caja_id]);
		// Locking read para no volver a la foto vieja de REPEATABLE READ tras esperar el candado.
		return Caja::where('id', $this->caja_id)->lockForUpdate()->first()->current_apertura_caja_id;
	}
	function set_saldos() {

		$caja = Caja::where('id', $this->movimiento_caja->caja_id)->lockForUpdate()->first();
		$saldo_actual = $caja->saldo;

		$saldo = null;

		if (!is_null($this->movimiento_caja->ingreso)) {

			$saldo = $saldo_actual + $this->movimiento_caja->ingreso;

		} else if (!is_null($this->movimiento_caja->egreso)) {

			$saldo = $saldo_actual - $this->movimiento_caja->egreso;

		}

		$this->movimiento_caja->saldo = $saldo;
		$this->movimiento_caja->save();

		$this->movimiento_caja->caja->saldo = $saldo;
		$this->movimiento_caja->caja->save();
	}

	function set_apertura_caja_ingresos_egresos($apertura_caja_id) {

		$apertura_caja = AperturaCaja::where('id', $apertura_caja_id)->lockForUpdate()->first();

		$total_ingresos = 0;
		$total_egresos = 0;

		foreach (MovimientoCaja::where('apertura_caja_id', $apertura_caja_id)->lockForUpdate()->get() as $movimiento) {
			
			if (!is_null($movimiento->ingreso)) {

				$total_ingresos += (float)$movimiento->ingreso;
			} else if (!is_null($movimiento->egreso)) {

				$total_egresos += (float)$movimiento->egreso;

			}
		}

		$apertura_caja->total_ingresos = $total_ingresos;
		$apertura_caja->total_egresos = $total_egresos;
		$apertura_caja->save();
	}

    static function recalcular_saldos($desde_movimiento_caja = null, $caja_id = null) {

    	$caja = null;

    	$movimientos_caja_para_actualizar = null;
    	$saldo_anterior = null;

    	if (!is_null($desde_movimiento_caja)) {

    		$caja = Caja::find($desde_movimiento_caja->caja_id);

	    	$desde_movimiento_caja = Self::actualizar_saldo($desde_movimiento_caja);

	    	$movimientos_caja_para_actualizar = MovimientoCaja::where('apertura_caja_id', $desde_movimiento_caja->apertura_caja_id)
	    											->where('created_at', '>', $desde_movimiento_caja->created_at)
	    											->orderBy('created_at', 'ASC')
	    											->get();

	    	$saldo_anterior = $desde_movimiento_caja->saldo;

    	} else if (!is_null($caja_id)) {

    		$caja = Caja::find($caja_id);

	    	Log::info('Entro con caja_id: '.$caja_id);
	    	Log::info('current_aperutra_caja_id: '.$caja->current_apertura_caja_id);

	    	$movimientos_caja_para_actualizar = MovimientoCaja::where('apertura_caja_id', $caja->current_apertura_caja_id)
	    											->orderBy('created_at', 'ASC')
	    											->get();

	    	$apertura_caja = AperturaCaja::find($caja->current_apertura_caja_id);

	    	if (!is_null($apertura_caja)) {

	    		$saldo_anterior = $apertura_caja->saldo_apertura;
	    	} else {

	    		Log::info('apertura_caja es NULL');
	    		Log::info('current_apertura_caja_id: '.$caja->current_apertura_caja_id);
	    	}
    	}



		$total_ingresos = 0;
		$total_egresos = 0;

    	foreach ($movimientos_caja_para_actualizar as $movimiento_caja) {
    		
			if (!is_null($movimiento_caja->ingreso)) {

				$nuevo_saldo = $saldo_anterior + $movimiento_caja->ingreso;

			} else if (!is_null($movimiento_caja->egreso)) {

				$nuevo_saldo = $saldo_anterior - $movimiento_caja->egreso;

			}

    		$movimiento_caja->saldo = $nuevo_saldo;
    		$movimiento_caja->save();

    		$saldo_anterior = $movimiento_caja->saldo;
    	}



		$apertura_caja->total_ingresos = $total_ingresos;
		$apertura_caja->total_egresos = $total_egresos;
		$apertura_caja->save();

    	$caja->saldo = $saldo_anterior;
    	$caja->save();

    }

    static function actualizar_saldo($movimiento_caja) {

    	$movimiento_anterior = MovimientoCaja::where('apertura_caja_id', $movimiento_caja->apertura_caja_id)
    											->where('created_at', '<', $movimiento_caja->created_at)
    											->orderBy('created_at', 'DESC')
    											->first();

    	if (!is_null($movimiento_anterior)) {

    		$nuevo_saldo = null;

			if (!is_null($movimiento_caja->ingreso)) {

				$nuevo_saldo = $movimiento_anterior->saldo + $movimiento_caja->ingreso;

			} else if (!is_null($movimiento_caja->egreso)) {

				$nuevo_saldo = $movimiento_anterior->saldo - $movimiento_caja->egreso;

			}

			Log::info('Nuevo saldo para movimiento con saldo de '.$movimiento_caja->saldo);

			$movimiento_caja->saldo = $nuevo_saldo;
			$movimiento_caja->save();
			Log::info('Nuevo saldo: '.$nuevo_saldo);
			
    	} else {

    		$nuevo_saldo = $movimiento_caja->apertura_caja->saldo_apertura;

    		if (!is_null($movimiento_caja->ingreso)) {
    			$nuevo_saldo += $movimiento_caja->ingreso;
    		} else if (!is_null($movimiento_caja->egreso)) {
    			$nuevo_saldo -= $movimiento_caja->egreso;
    		}

			$movimiento_caja->saldo = $nuevo_saldo;
			$movimiento_caja->save();
			Log::info('No habia movimientos anteriores');
    	}

    	return $movimiento_caja;
    }

}
