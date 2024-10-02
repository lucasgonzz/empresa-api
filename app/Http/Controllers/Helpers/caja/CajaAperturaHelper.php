<?php

namespace App\Http\Controllers\Helpers\caja;

use App\Http\Controllers\Helpers\UserHelper;
use App\Models\AperturaCaja;
use App\Models\Caja;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class CajaAperturaHelper {

	public $caja;
	public $apertura_caja;

	function __construct($caja_id) {

		$this->caja = Caja::find($caja_id);
	}

	function abrir_caja() {

		Log::info('se abrio caja');

		$this->crear_apertura();

		$this->marcar_abierta();

	}

	function marcar_abierta() {

		$this->caja->abierta = 1;

		$this->caja->abierta_at = Carbon::now();
		$this->caja->cerrada_at = null;

		$this->caja->current_apertura_caja_id = $this->apertura_caja->id;

		$this->caja->save();

		Log::info('se puso current_apertura_caja_id: '.$this->apertura_caja->id);
	}

	function crear_apertura() {

		$this->apertura_caja = AperturaCaja::create([
			'saldo_apertura'		=> $this->caja->saldo,
			'apertura_employee_id'	=> UserHelper::userId(false),
			'caja_id'				=> $this->caja->id,
		]);
	}
	
}