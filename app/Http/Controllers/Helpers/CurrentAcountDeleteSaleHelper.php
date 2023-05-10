<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\Helpers\CurrentAcountHelper;
use App\Models\CurrentAcount;
use Illuminate\Support\Facades\Log;

class CurrentAcountDeleteSaleHelper {
	
	static function deleteSale($sale) {
        $current_acount = CurrentAcount::where('sale_id', $sale->id)
                                        ->whereNull('haber')
                                        ->first();
        if (!is_null($current_acount)) {
	        $current_acount->delete();
	        Self::recalculatePagos($sale);
        	Log::info('se elimino');
        } else {
        	Log::info('No se elimino');
        }
	}

	static function recalculatePagos($sale) {
		$pagos = CurrentAcount::where('client_id', $sale->client_id)
								->where('created_at', '>', $sale->created_at)
								->whereNotNull('haber')
								->orderBy('created_at', 'ASC')
								->get();
		foreach ($pagos as $pago) {
        	$pago->saldo = CurrentAcountHelper::getSaldo('client', $sale->client_id, $pago) - $pago->haber;
        	$pago->detalle = CurrentAcountHelper::procesarPago('client', $sale->client_id, $pago->haber, $pago, $pago->to_pay_id);
		}
	}

}