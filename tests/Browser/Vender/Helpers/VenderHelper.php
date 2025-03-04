<?php

namespace Tests\Browser\Vender\Helpers;

use App\Models\Address;


class VenderHelper {

     static function btn_guardar($browser) {

        $browser->pause(1000);
        $browser->press('@btn_vender');
        dump('Venta guardada');
        $browser->pause(2000);
    }

    

    static function set_address($browser, $address_name) {

        $address = Address::where('street', $address_name)->first();
        
        return $browser->select('@address_id', $address->id);
    }
}
