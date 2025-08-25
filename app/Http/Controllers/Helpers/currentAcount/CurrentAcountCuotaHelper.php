<?php

namespace App\Http\Controllers\Helpers\currentAcount;

use App\Http\Controllers\Helpers\caja\MovimientoCajaHelper;
use App\Models\AperturaCaja;
use App\Models\Caja;
use App\Models\PaymentPlanCuota;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class CurrentAcountCuotaHelper {

    static function pagar_cuota($current_acount, $request) {

        if (
            isset($request->payment_plan_cuota)
            && !is_null($request->payment_plan_cuota)
        ) {

            Log::info('entro a pagar cuota');

            $cuota = PaymentPlanCuota::find($request->payment_plan_cuota['id']);
            
            if ($cuota) {

                $amount_paid = $cuota->amount_paid;

                if (is_null($amount_paid)) {
                    $amount_paid = 0;
                }
                
                $amount_paid += $current_acount->haber;

                Log::info('comparando '.round($amount_paid, 2).' = '.round($cuota->amount, 2));

                if (round($amount_paid, 2) >= round($cuota->amount, 2)) {
                    Log::info('Entro');    
                    $cuota->estado = 'pagado';

                } 


                $cuota->amount_paid = $amount_paid;

                $cuota->paid_at = Carbon::now();
                $cuota->save();
                
                Log::info('Se marco como paga la cuota '.$cuota->id);
            }
        }
    }
	
}