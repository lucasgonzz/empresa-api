<?php

namespace App\Http\Controllers\Helpers\currentAcount;

use App\Http\Controllers\Helpers\caja\MovimientoCajaHelper;
use App\Models\AperturaCaja;
use App\Models\Caja;
use App\Models\CurrentAcount;
use App\Models\PaymentPlanCuota;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class CurrentAcountCuotaHelper {

    /**
     * Resuelve el to_pay_id del pago que está entrando por CurrentAcountController::pago()
     * (tanda correctivos 2408, ítem 13).
     *
     * Regla de Lucas (informe 20260824 §7): el pago registrado desde una CUOTA de un plan
     * de pago se imputa a LA VENTA sobre la que se creó el plan, no al comprobante sin
     * saldar más viejo (que es el default FIFO general). El motor de imputación dirigida ya
     * existe — CurrentAcountPagoHelper::setSinPagar() usa to_pay_id en la primera iteración
     * y sigue en FIFO con el sobrante — lo único que faltaba era cargarle el to_pay_id a
     * este camino: la SPA (PagarBtn.vue de cuotas-pendientes) manda payment_plan_cuota pero
     * nunca to_pay.
     *
     * Prioridades:
     *  1. Un to_pay explícito del request se respeta siempre (imputación elegida a mano).
     *  2. Con payment_plan_cuota, se busca el débito pendiente de la venta de la cuota en
     *     la cuenta del pago (mismo criterio que la NC dirigida de notaCredito()).
     *  3. Sin ninguno de los dos, null: el pago entra a la cola FIFO como siempre.
     *
     * @param \Illuminate\Http\Request $request Request del pago (credit_account_id, to_pay, payment_plan_cuota).
     * @return int|null Id del débito de current_acounts a saldar primero, o null.
     */
    static function get_to_pay_id($request) {

        // 1. Imputación explícita del request: manda.
        if (!is_null($request->to_pay) && isset($request->to_pay['id'])) {
            return $request->to_pay['id'];
        }

        // 2. Pago desde una cuota de plan: dirigir a la venta del plan.
        if (
            isset($request->payment_plan_cuota)
            && !is_null($request->payment_plan_cuota)
            && isset($request->payment_plan_cuota['id'])
        ) {

            $cuota = PaymentPlanCuota::find($request->payment_plan_cuota['id']);

            if (!is_null($cuota) && !is_null($cuota->sale_id)) {

                /** Débito pendiente de la venta del plan en la cuenta sobre la que entra el pago. */
                $debito_de_la_venta = CurrentAcount::where('sale_id', $cuota->sale_id)
                                                    ->whereNull('haber')
                                                    ->where('credit_account_id', $request->credit_account_id)
                                                    ->whereIn('status', ['sin_pagar', 'pagandose'])
                                                    ->first();

                if (!is_null($debito_de_la_venta)) {

                    Log::info('Pago desde cuota '.$cuota->id.': imputacion dirigida al debito '.$debito_de_la_venta->id.' de la venta '.$cuota->sale_id);

                    return $debito_de_la_venta->id;
                }

                // La venta del plan no tiene débito pendiente en esta cuenta (ya saldada o
                // de otra moneda): el pago entra FIFO, igual que antes del ítem 13.
                Log::info('Pago desde cuota '.$cuota->id.': la venta '.$cuota->sale_id.' no tiene debito pendiente en la cuenta '.$request->credit_account_id.', entra FIFO');
            }
        }

        return null;
    }

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