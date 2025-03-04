<?php

namespace Tests\Browser\Helpers\Sale;

use Tests\Browser\Helpers\SaleHelper;


class ComboHelper
{
    
    static function add_combo($browser, $data) {

        // Busco y agrego article a la tabla
        $browser = Self::search_combo(
            $browser,
            $data['combo_name'],
            $data['amount'],
        );

        
        $browser->pause(2000);


        // Chequeo el TOTAL
        $browser = SaleHelper::check_total(
            $browser,
            $data['total_a_chequear'],
        );

        return $browser;

    }

    static function search_combo($browser, $combo_name, $amount) {

        $browser->click('#select-combo');

        $browser->pause(1000);

        $browser->type('#select-combo-search-modal-input', $combo_name)
                
                ->pause(500)

                ->keys('#select-combo-search-modal-input', ['{CONTROL}'])

                ->pause(1500)

                ->keys('#select-combo-search-modal-input', ['{ENTER}'])

                ->pause(500)

                ->waitFor('@article_amount')

                ->type('@article_amount', $amount)
                
                ->pause(1000)
                
                ->keys('@article_amount', ['{ENTER}']);
        
        return $browser;

    }
}
