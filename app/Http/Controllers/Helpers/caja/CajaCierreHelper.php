<?php

namespace App\Http\Controllers\Helpers\caja;

use App\Http\Controllers\Helpers\UserHelper;
use App\Models\AperturaCaja;
use App\Models\Caja;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class CajaCierreHelper {

	public $caja;
	public $apertura_caja;

	function __construct($caja_id) {

		$this->caja = Caja::find($caja_id);
	}

	function cerrar_caja() {

		$this->cerrar_apertura();

		$this->marcar_cerrada();

		Log::info('se cerro caja');
	}

	function marcar_cerrada() {

		$this->caja->abierta = 0;

		$this->caja->cerrada_at = Carbon::now();

		$this->caja->current_apertura_caja_id = null;

		$this->caja->save();
	}

	function cerrar_apertura() {

		$apertura_caja = AperturaCaja::find($this->caja->current_apertura_caja_id);

		$apertura_caja->cerrada_at 				= Carbon::now();
		$apertura_caja->saldo_cierre 			= $this->caja->saldo;

		/*
			🔴 Acá va `cierre_employee_id`, NO `apertura_employee_id`.

			Hasta agosto de 2026 esta línea escribía `apertura_employee_id`, y el daño era doble:
			`cierre_employee_id` quedaba SIEMPRE en null (nunca se supo quién cerró una caja) y
			además se pisaba el id de quien la había abierto.

			La tentación de "simplificarlo" de vuelta está en que las dos columnas guardan la
			misma expresión. Son distintas a propósito: `apertura_employee_id` es de
			`CajaAperturaHelper` y en el caso normal lo escribe otra persona, en otro momento.
			`AperturaCajaController::reabrir()` lo confirma: limpia SOLO el cierre.

			Y el `false` de `userId(false)` pide el usuario logueado, no el dueño de la cuenta:
			sin él todos los cierres quedarían a nombre del owner y la columna no serviría.
		*/
		$apertura_caja->cierre_employee_id 		= UserHelper::userId(false);

		$apertura_caja->save();
	}
	
}