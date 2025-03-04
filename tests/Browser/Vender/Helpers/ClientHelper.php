<?php

namespace Tests\Browser\Vender\Helpers;

use App\Models\Client;


class ClientHelper
{
    
    static function select_client($browser, $client_name) {

        $client = Client::where('name', $client_name)->first();

        $browser->click('@cliente');

        $browser->pause(1000);

        $browser->click('#select_client_vender');

        $browser->pause(1000);

        $browser->type('#select_client_vender-search-modal-input', $client_name)
                
                ->pause(500)

                ->keys('#select_client_vender-search-modal-input', ['{CONTROL}'])

                ->pause(500)

                ->keys('#select_client_vender-search-modal-input', ['{ENTER}']);
        
    }

    function omitir_en_cc($browser) {

        $browser->waitFor('#omitir_en_cuenta_corriente');
        $browser->click('#omitir_en_cuenta_corriente');
        $browser->pause('500');

    }
}
