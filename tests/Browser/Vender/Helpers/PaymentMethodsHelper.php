<?php

namespace Tests\Browser\Vender\Helpers;

use App\Models\CurrentAcountPaymentMethod;


class PaymentMethodsHelper {

    static function set_payment_methods($browser, $data) {

        if (
            !isset($data['set_remito'])
            || $data['set_remito']
        ) {

            $browser->click('@remito');
        }

        $browser->pause(1000);

        $browser->click('#btn_set_payment_methods');

        foreach ($data['payment_methods'] as $payment_method) {
            
            $amount = null;

            if (isset($payment_method['amount'])) {
                $amount = $payment_method['amount'];
            }

            Self::add_payment_method($browser, $payment_method['name'], $amount);

            $browser->pause(500);
        }

        Self::guardar($browser);
    }

    static function add_payment_method($browser, $payment_method, $amount = null) {

        $payment_method_model = CurrentAcountPaymentMethod::where('name', $payment_method)->first();

        if (!is_null($amount)) {

            $input = "#input_payment_method_{$payment_method_model->id}";
            
            $browser->waitFor($input);

            $browser->type($input, $amount);

        } else {

            $input = "#btn_agregar_total_payment_method_{$payment_method_model->id}";
            
            $browser->waitFor($input);

            $browser->click($input);

        }
    }

    static function guardar($browser) {

        $browser->click('#btn_guardar_payment_methods');

        $browser->pause(500);
    }

}
