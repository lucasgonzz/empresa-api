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
		$apertura_caja->apertura_employee_id 	= UserHelper::userId(false);

		$apertura_caja->save();
	}
	
}