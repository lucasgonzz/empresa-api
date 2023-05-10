<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\Helpers\CurrentAcountHelper;
use App\Models\CurrentAcount;

class CurrentAcountDeleteNotaDebitoHelper {
	
	static function deleteNotaDebito($nota_debito) {
        $current_acount = CurrentAcount::find($nota_debito->id);
        if (!is_null($current_acount)) {
	        $current_acount->delete();
	        Self::recalculatePagos($nota_debito);
        }
	}

	static function recalculatePagos($nota_debito) {
		$pagos = CurrentAcount::where('client_id', $nota_debito->client_id)
								->where('created_at', '>', $nota_debito->created_at)
								->whereNotNull('haber')
								->orderBy('created_at', 'ASC')
								->get();
		foreach ($pagos as $pago) {
        	$pago->saldo = CurrentAcountHelper::getSaldo('client', $nota_debito->client_id, $pago) - $pago->haber;
        	$pago->detalle = CurrentAcountHelper::procesarPago('client', $nota_debito->client_id, $pago->haber, $pago, $pago->to_pay_id);
		}
	}

}