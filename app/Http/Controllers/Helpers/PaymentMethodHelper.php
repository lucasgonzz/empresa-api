<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\Helpers\ChequeHelper;
use App\Models\CurrentAcountPaymentMethod;

class PaymentMethodHelper {

	static function attach_payment_methods($model, $payment_methods) {

		foreach ($payment_methods as $payment_method) {

            if (!is_null($payment_method['amount'])) {

                $amount 			= $payment_method['amount'];
                $amount_cotizado 	= isset($payment_method['amount_cotizado']) ? $payment_method['amount_cotizado'] : null;
                $cotizacion 		= isset($payment_method['cotizacion']) ? $payment_method['cotizacion'] : null;
                $moneda_id          = isset($payment_method['moneda_id']) ? $payment_method['moneda_id'] : null;
                $cuota_id 			= isset($payment_method['cuota_id']) ? $payment_method['cuota_id'] : null;
                $caja_id 			= null;

                $payment_method_model = CurrentAcountPaymentMethod::find($payment_method['current_acount_payment_method_id']);

                if (
                    !is_null($payment_method_model->type) 
                    && $payment_method_model->type->slug == 'tarjeta_de_credito' 
                    && isset($request->monto_credito_real)
                    && !is_null($request->monto_credito_real)
                ) {

                    $amount = $request->monto_credito_real;
                }


                if (isset($payment_method['caja_id'])
                    && $payment_method['caja_id'] != 0) {
                    $caja_id = $payment_method['caja_id'];
                }
                
	            if (
	            	!is_null($payment_method_model->type) 
                    && $payment_method_model->type->slug == 'cheque'
	            ) {
	                ChequeHelper::crear_cheque($model, $payment_method);
	            }

                $model->current_acount_payment_methods()->attach($payment_method['current_acount_payment_method_id'],[
                    'amount'            => $amount,
                    'caja_id'           => $caja_id,
                    'amount_cotizado'   => $amount_cotizado,
                    'cotizacion'        => $cotizacion,
                    'moneda_id'         => $moneda_id,
                    'cuota_id'          => $cuota_id,
                ]);

            }
        }
	}
	
}