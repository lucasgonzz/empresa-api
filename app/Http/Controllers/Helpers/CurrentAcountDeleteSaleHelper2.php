<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\Helpers\CurrentAcountHelper;
use App\Models\CurrentAcount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CurrentAcountDeleteSale2Helper {
	
	static function deleteSale($sale) {
        $current_acount = CurrentAcount::where('sale_id', $sale->id)
                                        ->whereNull('haber')
                                        ->first();
        if (!is_null($current_acount)) {
        	$pagado_por = $current_acount->pagado_por;
	        $current_acount->pagado_por()->detach();
	        $current_acount->delete();
	        Self::recalculatePagos($sale, $pagado_por); 
        	Log::info('se elimino la cuenta corriente de la venta N°'.$sale->num);
        } else {
        	Log::info('No se elimino');
        }
	}

	static function recalculatePagos($sale, $pagado_por) {
		Log::info('recalculatePagos $pagado_por');
		Log::info($pagado_por);
		foreach ($pagado_por as $pago) {
			if ($pago->pivot->pagado < $pago->pivot->total_pago) {
				Log::info('El pago de '.$pago->pivot->total_pago.' se uso para pagar otras ventas y no las pago por compelto');
				$otras_ventas_pagadas = Self::getOtrasVentasPagadas($pago);
				foreach ($otras_ventas_pagadas as $otra_venta_pagada) {
					$otra_venta_pagada->pagandose = $otra_venta_pagada->debe;
					$otra_venta_pagada->pagandose -= $pago->pivot->pagado;
					Log::info('El pagandose de '.$otra_venta_pagada->detalle.' quedo en '.$otra_venta_pagada->pagandose);
					if ($otra_venta_pagada->pagandose > 0) {
						$otra_venta_pagada->status = 'pagandose';
					} else {
						$otra_venta_pagada->status = 'sin_pagar';
					}
					Log::info('Se puso status en '.$otra_venta_pagada->status);
					$otra_venta_pagada->save();
					$otra_venta_pagada->pagado_por()->detach();
				}
			}
		}

		Log::info('POR ACA');
		foreach ($pagado_por as $_pago) {
			$pago = CurrentAcount::find($_pago->pivot->haber_id);
        	$pago->detalle = CurrentAcountHelper::procesarPago('client', $sale->client_id, $pago->haber, $pago, $pago->to_pay_id);
        	$pago->saldo = CurrentAcountHelper::getSaldo('client', $sale->client_id, $pago) - $pago->haber;
        	$pago->save();
		}


		// $pagos = CurrentAcount::where('client_id', $sale->client_id)
		// 						->where('created_at', '>', $sale->created_at)
		// 						->whereNotNull('haber')
		// 						->orderBy('created_at', 'ASC')
		// 						->get();
		// $primer_pago = Self::getPrimerPago($pagado_por);
		// $pagos = CurrentAcount::where('client_id', $sale->client_id)
		// 						->where('created_at', '>=', $primer_pago->created_at)
		// 						->whereNotNull('haber')
		// 						->orderBy('created_at', 'ASC')
		// 						->get();
		// Self::restartNextSales($primer_pago);
		// foreach ($pagos as $pago) {
		// 	Log::info('Recalculando pago N°'.$pago->num_receipt);
        // 	$pago->detalle = CurrentAcountHelper::procesarPago('client', $sale->client_id, $pago->haber, $pago, $pago->to_pay_id);
        // 	$pago->saldo = CurrentAcountHelper::getSaldo('client', $sale->client_id, $pago) - $pago->haber;
		// 	// $otras_ventas_pagadas = Self::getOtrasVentasPagadas($pago);
		// 	// if (count($otras_ventas_pagadas) == 0) {
	    //     // 	$pago->detalle = CurrentAcountHelper::procesarPago('client', $sale->client_id, $pago->haber, $pago, $pago->to_pay_id);
	    //     // 	$pago->saldo = CurrentAcountHelper::getSaldo('client', $sale->client_id, $pago) - $pago->haber;
		// 	// } else {
		// 	// 	foreach ($otras_ventas_pagadas as $otras_ventas_pagada) {
		// 	// 		// if ($otras_ventas_pagada->debe < $otras_ventas_pagada)
		// 	// 	}
		// 	// }
		// }
	}

	static function getOtrasVentasPagadas($pago) {
		$otras_ventas_pagadas = [];
		$pagados_por = DB::table('pagado_por')
							->where('haber_id', $pago->pivot->haber_id)
							->get();
		foreach ($pagados_por as $pagado_por) {
			$otras_ventas_pagadas[] = CurrentAcount::find($pagado_por->debe_id);
		}
		Log::info('otras_ventas_pagadas');
		foreach ($otras_ventas_pagadas as $otras_venta) {
			Log::info($otras_venta->detalle);
		}
		return $otras_ventas_pagadas;
	}

	static function getPrimerPago($pagado_por) {
		$current_acount = CurrentAcount::find($pagado_por[0]->pivot->haber_id);
		Log::info('Primer pago: '.$current_acount->num_receipt);
		return $current_acount;
	}

	static function restartNextSales($primer_pago) {
		$current_acounts = CurrentAcount::where('client_id', $primer_pago->client_id)
										->where('created_at', '>', $primer_pago->created_at)
										->whereNotNull('debe')
										->orderBy('created_at', 'ASC')
										->get();
										// ->update([
										// 	'status' => 'sin_pagar',
										// 	'pagandose'	=> null,
										// ]);
		foreach ($current_acounts as $current_acount) {
			Log::info('Reseteando '.$current_acount->detalle);
			$current_acount->status = 'sin_pagar';
			$current_acount->pagandose = null;
			$current_acount->save();
		}
	}

}