<?php

namespace App\Http\Controllers\Helpers;

use App\Models\CurrentAcount;
use Illuminate\Support\Facades\Log;

class CUrrentAcountDeletePagoHelper {
		 
	function __construct($model_name, $pago) {
		$this->model_name = $model_name;
		$this->pago = $pago;
		$this->haber = $pago->haber;
	}

	function deletePago() {
		if (!is_null($this->pago->to_pay_id)) {
			$current_acount = CurrentAcount::find($this->pago->to_pay_id);
			if (!is_null($current_acount)) {
				if ($current_acount->status == 'pagandose') {
					$this->haber -= $current_acount->pagandose;
					$current_acount->pagandose = 0;
					$current_acount->save();
				} else if ($current_acount->status == 'pagado') {
					$this->haber -= $current_acount->debe;
					$current_acount->status = 'sin_pagar';
					$current_acount->save();
				}
			}
		} 
		$this->resetPagandose();

		$this->resetPagado();
	}

	function resetPagandose() {
		$pagandose = $this->getPagandose();
		while (!is_null($pagandose) && $this->haber > 0) {
			$this->haber -= $pagandose->pagandose;
			Log::info('Se resto el pagandose '.$pagandose->pagandose.' de la venta n° '.$pagandose->sale->num);
			Log::info('El haber quedo en '.$this->haber);
			$pagandose->pagandose = 0;
			$pagandose->status = 'sin_pagar';
			$pagandose->save();
			$pagandose = $this->getPagandose();
		}
	}

	function resetPagado() {
		$pagado = $this->getPagado();
		while (!is_null($pagado) && $this->haber > 0) {
			$this->haber -= $pagado->debe;
			// Log::info('Se resto el debe de la venta n° '.$pagado->sale->num);
			Log::info('El haber quedo en '.$this->haber);
			$pagado->pagandose = 0;
			$pagado->status = 'sin_pagar';
			$pagado->save();
			$pagado = $this->getPagado();
		}
	}

	function getPagandose() {
		$pagandose = CurrentAcount::where($this->model_name.'_id', $this->pago->{$this->model_name.'_id'})
									->where('status', 'pagandose')
									->orderBy('created_at', 'ASC')
									->first();
		return $pagandose;
	}

	function getPagado() {
		$pagado = CurrentAcount::where($this->model_name.'_id', $this->pago->{$this->model_name.'_id'})
									->where('status', 'pagado')
									->orderBy('created_at', 'ASC')
									->first();
		return $pagado;
	}

}