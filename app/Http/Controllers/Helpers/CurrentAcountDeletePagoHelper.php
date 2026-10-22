<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\Helpers\currentAcount\CurrentAcountPagoAltaHelper;
use App\Models\CurrentAcount;
use Illuminate\Support\Facades\Log;

class CurrentAcountDeletePagoHelper {
		 
	function __construct($model_name, $pago) {
		$this->model_name = $model_name;
		$this->pago = $pago;
		$this->haber = $pago->haber;
		Log::info('Entro en CurrentAcountDeletePagoHelper');
	}

	function deletePago() {

		/*
		 * Los débitos dirigidos del pago: el `to_pay_id` de siempre y, si el pago fue a varios
		 * movimientos (misión pago-cc-multiples-movimientos, 22/10/2026), todos los de `to_pay_ids`
		 * (el primero de la lista es el mismo `to_pay_id`, por eso no se repite). Sin lista queda
		 * exactamente el único débito de antes.
		 */
		$to_pay_ids = [];
		if (!is_null($this->pago->to_pay_id)) {
			$to_pay_ids[] = (int) $this->pago->to_pay_id;
		}
		$lista = CurrentAcountPagoAltaHelper::to_pay_ids_normalizados($this->pago->to_pay_ids);
		if (!is_null($lista)) {
			foreach ($lista as $id) {
				if (!in_array($id, $to_pay_ids, true)) {
					$to_pay_ids[] = $id;
				}
			}
		}

		foreach ($to_pay_ids as $to_pay_id) {
			$current_acount = CurrentAcount::find($to_pay_id);
			if (!is_null($current_acount)) {
				if ($current_acount->status == 'pagandose') {
					$this->haber -= $current_acount->pagandose;
					$current_acount->pagandose = 0;
					$current_acount->status = 'sin_pagar';
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