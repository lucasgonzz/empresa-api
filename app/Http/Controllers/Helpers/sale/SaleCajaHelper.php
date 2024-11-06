<?php

namespace App\Http\Controllers\Helpers\sale;

use App\Http\Controllers\Helpers\caja\MovimientoCajaHelper;
use Illuminate\Support\Facades\Log;


class SaleCajaHelper {

	static function check_caja($sale) {

		Log::info('check_caja para la venta N° '.$sale->num.'. caja_id: '.$sale->caja_id);

		if (count($sale->current_acount_payment_methods) >= 1
			||
			(
				!is_null($sale->caja_id)
				&& $sale->caja_id != 0
				&&
				(
					is_null($sale->client_id)
					|| $sale->omitir_en_cuenta_corriente
				)
			)
		) {

			Self::crear_movimiento_caja($sale);
		} else {
			Log::info('No se creo movimiento en caja');
		}
	}

	static function crear_movimiento_caja($sale) {

        $helper = new MovimientoCajaHelper();

		foreach ($sale->current_acount_payment_methods as $payment_method) {

			if (!is_null($payment_method->pivot->caja_id)
			&& $payment_method->pivot->caja_id != 0) {
				
	 	        $data = [
		            'concepto_movimiento_caja_id'   => 1,
		            'ingreso'                       => $payment_method->pivot->amount,
		            'egreso'                        => null,
		            'notas'                         => '',
		            'sale_id'                       => $sale->id,
		            'caja_id'                       => $payment_method->pivot->caja_id,
		        ];

		    	$helper->crear_movimiento($data);
			}

        }
	}

}