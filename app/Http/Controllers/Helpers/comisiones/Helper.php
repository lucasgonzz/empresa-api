<?php

namespace App\Http\Controllers\Helpers\comisiones;


class Helper {
	
    static function get_status($sale) {

        if (
            !is_null($sale->client_id)
            && !$sale->omitir_en_cuenta_corriente
            && $sale->seller->commission_after_pay_sale
        ) {

            return 'inactive';
        }
        return 'active';
    }

}