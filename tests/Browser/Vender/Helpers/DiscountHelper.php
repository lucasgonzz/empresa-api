<?php

namespace Tests\Browser\Vender\Helpers;

use App\Models\Discount;
use App\Models\Surchage;


class DiscountHelper {

    static function add_discount($browser, $discount, $set_client_page = true) {

        if ($set_client_page) {
            $browser->click('@cliente');
        }

        $browser->pause(500);

        $discount_model = Discount::where('name', $discount)->first();
        $discount_checkbox = "#discount_{$discount_model->id}";

        $browser->waitFor($discount_checkbox, 10);
        $browser->click($discount_checkbox);
    }

    static function add_surchage($browser, $surchage, $set_client_page = true) {

        if ($set_client_page) {
            $browser->click('@cliente');
        }

        $browser->pause(1000);

        $surchage_model = Surchage::where('name', $surchage)->first();
        $surchage_checkbox = "#surchage_{$surchage_model->id}";

        $browser->waitFor($surchage_checkbox, 10);
        $browser->click($surchage_checkbox);
        
        $browser->pause(500);
    }

    static function aplicar_descuentos_a_servicios($browser, $set_client_page = true) {

        if ($set_client_page) {
            $browser->click('@cliente');
            $browser->pause(500);
        }

        $browser->waitFor('#aplicar_descuentos_a_servicios');
        $browser->click('#aplicar_descuentos_a_servicios');
        
        $browser->pause(500);
    }

    static function aplicar_recargos_a_servicios($browser, $set_client_page = true, $client_name = null) {

        if ($set_client_page) {
            $btn = '@cliente';
            if (!is_null($client_name)) {
                $btn .= " ({$client_name})";
            }
            $browser->click($btn);
            $browser->pause(500);
        }

        $browser->waitFor('#aplicar_recargos_a_servicios');
        $browser->click('#aplicar_recargos_a_servicios');
        
        $browser->pause(500);
    }
}
