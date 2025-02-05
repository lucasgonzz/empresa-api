<?php

namespace Tests\Browser\Helpers;

use App\Models\Address;


class SaleHelper
{

    static function btn_guardar($browser) {

        return $browser->pause(1000)
                    ->press('@btn_vender')
                    ->pause(1000);
    }

    static function add_article($browser, $data) {

        // Busco y agrego article a la tabla
        $browser = Self::search_article(
            $browser,
            $data['bar_code'],
            $data['amount'],
        );

        
        $browser->pause(1000);


        // Chequeo el TOTAL
        $browser = Self::check_total(
            $browser,
            $data['total_a_chequear'],
        );

        return $browser;

    }

    static function cambiar_amount($browser, $index, $new_amount) {
        return $browser
                    ->type('@amount_'.$index, $new_amount);
    }
    

    static function search_article($browser, $bar_code, $amount) {

        return $browser
                ->waitFor('@article_bar_code')
                ->type('@article_bar_code', $bar_code)
                ->keys('@article_bar_code', ['{ENTER}'])
                
                ->pause(1000)
                ->waitFor('@article_amount')
                ->type('@article_amount', $amount)
                ->pause(1000)
                ->keys('@article_amount', ['{ENTER}']);

    }


    static function check_total($browser, $total) {
        
        return $browser->assertSeeIn('@total', $total);
    }


    static function set_address($browser, $address_name) {

        $address = Address::where('street', $address_name)->first();
        
        return $browser->select('@address_id', $address->id);
    }
}
