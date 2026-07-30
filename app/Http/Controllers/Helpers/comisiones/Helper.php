<?php

namespace App\Http\Controllers\Helpers\comisiones;


class Helper {
	
    /**
     * Grupo 268 · Prompt 02, bug A: la comision solo tiene que nacer pendiente si REALMENTE va a
     * existir un movimiento de cuenta corriente que pueda saldarla despues. Antes faltaba mirar
     * save_current_acount: una venta a cliente sin cuenta corriente (save_current_acount = false)
     * dejaba la comision inactive para siempre, porque SaleHelper::create_current_acount() nunca
     * crea el movimiento que la despertaria.
     *
     * @param \App\Models\Sale $sale
     * @return string 'active' o 'inactive'
     */
    static function get_status($sale) {

        if (
            !is_null($sale->client_id)
            && $sale->save_current_acount
            && !$sale->omitir_en_cuenta_corriente
            && $sale->seller->commission_after_pay_sale
        ) {

            return 'inactive';
        }
        return 'active';
    }

}