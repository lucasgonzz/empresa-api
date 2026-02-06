<?php

namespace Tests\Browser\Vender\Helpers;

use App\Models\Address;


class RemitoHelper {


    static function cambiar_amount($browser, $data) {
        $browser->pause(500);
        $browser->type('@amount_'.$data['index'], $data['new_amount']);
    }

    static function get_bar_code($bar_code) {
        // $articles_num = [
        //     '001'      => 5,
        //     '002'     => 6,
        // ];
        // if (config('app.FOR_USER') == 'feito') {
        //     return $articles_num[$bar_code];
        // }
        return $bar_code;
    }

    static function add_article_bar_code($browser, $bar_code, $amount = null) {

        $bar_code = Self::get_bar_code($bar_code);

        $browser->waitFor('@article_bar_code');

        $browser->waitUntilEnabled('@article_bar_code');

        $browser->click('@article_bar_code');

        // $browser->clear('@article_bar_code');

        $browser->type('@article_bar_code', $bar_code);

        $browser->pause(500);

        $browser->keys('@article_bar_code', ['{ENTER}']);
        
        $browser->pause(1000);

        if ($amount) {

            if ($browser->element('@article_amount')) {

                $browser->waitFor('@article_amount');
                $browser->pause(500);

                $browser->click('@article_amount');
                $browser->type('@article_amount', $amount);
                
                $browser->pause(500);

                $browser->keys('@article_amount', ['{ENTER}']);
            }

        }

    }

    static function add_service($browser, $service) {

        $browser->waitFor('#service_name');

        $browser->type('#service_name', $service['name']);

        $browser->pause(500);

        $browser->keys('#service_name', ['{ENTER}']);
        
        $browser->pause(500);

        $browser->waitFor('#service_price');

        $browser->type('#service_price', $service['price']);
        
        $browser->pause(500);

        $browser->keys('#service_price', ['{ENTER}']);

        $browser->pause(1000);
    }

    static function forzar_total($browser, $total, $check_total) {

        $browser->click('#btn_forzar_total');

        $browser->pause(500);

        $browser->type('#precio-final-forzado', $total);

        $browser->pause(500);

        $browser->click('#btn_calcular_descuento');

        Self::check_total($browser, $check_total);
    }


    static function check_total($browser, $total) {
        
        $browser->assertSeeIn('@total', $total);

        dump("Total OK ($total)");
    }

}
