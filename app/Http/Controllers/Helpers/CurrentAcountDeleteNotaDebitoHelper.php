<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\Helpers\CurrentAcountHelper;
use App\Http\Controllers\Helpers\CurrentAcountPagoHelper;
use App\Models\CurrentAcount;

class CurrentAcountDeleteNotaDebitoHelper {
	
	static function deleteNotaDebito($nota_debito, $model_name) {
        $current_acount = CurrentAcount::find($nota_debito->id);
        if (!is_null($current_acount)) {
	        $current_acount->delete();
	        Self::recalculatePagos($nota_debito, $model_name);
        }
	}

	static function recalculatePagos($nota_debito, $model_name) {
		$pagos = CurrentAcount::where($model_name.'_id', $nota_debito->{$model_name.'_id'})
								->where('created_at', '>', $nota_debito->created_at)
								->whereNotNull('haber')
								->orderBy('created_at', 'ASC')
								->get();
		foreach ($pagos as $pago) {
        $pago->saldo = CurrentAcountHelper::getSaldo($model_name, $nota_debito->{$model_name.'_id'}, $pago) - $pago->haber;

        $pago_helper = new CurrentAcountPagoHelper($model_name, $nota_debito->{$model_name.'_id'}, $pago);
        $pago_helper->init();

        // $pago->detalle = CurrentAcountHelper::procesarPago('client', $nota_debito->client_id, $pago->haber, $pago, $pago->to_pay_id);
		}
	}

}