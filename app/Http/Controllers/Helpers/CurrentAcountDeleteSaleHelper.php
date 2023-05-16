<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\Helpers\CurrentAcountPagoHelper;
use App\Models\CurrentAcount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CurrentAcountDeleteSaleHelper {

	public $model_name;
	public $model_id;
	public $sale;
	
	function __construct($model_name, $model_id, $sale) {
		$this->model_name = $model_name;
		$this->model_id = $model_id;
		$this->sale = $sale;
		$this->deleteSaleCurrentAcount();
		$this->setPagosPosteriores();
		$this->updateSalesStatusPosteriores();
	}

	function setPagosPosteriores() {
		$this->pagos_posteriores = CurrentAcount::where($this->model_name.'_id', $this->model_id)
												->whereNotNull('haber')
												->where('created_at', '>', $this->sale->created_at)
												->orderBy('created_at', 'ASC')
												->get();
	}

	function updateSalesStatusPosteriores() {
		Log::info('Pagos posteriores a la venta N°'.$this->sale->num);
		foreach ($this->pagos_posteriores as $pago) {
			Log::info('Pago N°'.$pago->num_receipt);
		}
		Log::info('--------------------------------------------');
		foreach ($this->pagos_posteriores as $pago) {
			$this->updateVentasPagadasPorElPago($pago);
		}
	}

	function updateVentasPagadasPorElPago($pago) {
		$ventas_pagadas_por_el_pago = DB::table('pagado_por')
											->where('haber_id', $pago->id)
											->orderBy('created_at', 'ASC')
											->get();
	
		Log::info('Actualizado las ventas pagadas por el pago N°'.$pago->num_receipt);
		foreach ($ventas_pagadas_por_el_pago as $pagado_por) {
			$venta_pagada = CurrentAcount::find($pagado_por->debe_id);
			Log::info('El pago N°'.$pago->num_receipt.' pago '.$pagado_por->pagado.' a la venta '.$venta_pagada->detalle);
			$venta_pagada->status = 'sin_pagar';
			$venta_pagada->pagandose -= $pagado_por->pagado;
			$venta_pagada->save();
			$venta_pagada->pagado_por()->detach($pago->id);
			// $this->deletePagadoPor($venta_pagada, $pago);
			Log::info('El pagandose de la venta quedo en '.$venta_pagada->pagandose);
		}

		Log::info('--------------------------------------------');
	
		foreach ($ventas_pagadas_por_el_pago as $pagado_por) {
			$venta_pagada = CurrentAcount::find($pagado_por->debe_id);
			Log::info('Historial de pagos de '.$venta_pagada->detalle);
			foreach ($venta_pagada->pagado_por as $pagado_por_pago) {
				Log::info('La '.$venta_pagada->detalle.' fue pagada por  el pago N°'.$pagado_por_pago->num_receipt);
			}
			Log::info('--------------------------------------------');
		}
		Log::info('Pasando al Siguiente pago');
		Log::info('--------------------------------------------');
	
	}

	function deletePagadoPor($venta, $pago) {
		DB::table('pagado_por')
				->where('debe_id', $venta->id)
				->where('haber_id', $pago->id)
				->delete();
	}

	function init() {
		foreach ($this->pagos_posteriores as $pago) {
			$pago_helper = new CurrentAcountPagoHelper($this->model_name, $this->model_id, $pago);
			$pago_helper->init();
		}
	}

	function deleteSaleCurrentAcount() {
		$current_acount = CurrentAcount::find($this->sale->current_acount->id);
		$current_acount->pagado_por()->detach();
		$current_acount->delete();
	}

}