<?php

namespace Tests\Browser\Vender\Helpers;

use App\Models\Address;
use App\Models\CurrentAcountPaymentMethod;


class VenderHelper {

     static function btn_guardar($browser) {

        $browser->pause(1000);
        $browser->press('@btn_vender');
        dump('Venta guardada');
        $browser->pause(2000);
    }


    static function set_address($browser, $address_name) {
        
        $browser->pause(500);

        $address = Address::where('street', $address_name)->first();
        
        $browser->select('@address_id', $address->id);

    }

    static function set_payment_method($browser, $payment_method_name) {
        
        $browser->pause(500);

        $payment_method = CurrentAcountPaymentMethod::where('name', $payment_method_name)->first();
        
        $browser->select('#vender_payment_method_id', $payment_method->id);

    }
}
