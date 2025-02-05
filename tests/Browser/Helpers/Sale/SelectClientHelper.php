<?php

namespace Tests\Browser\Helpers\Sale;

use App\Models\Client;


class SelectClientHelper
{
    
    static function select_client($browser, $client_name)
    {

        $client = Client::where('name', $client_name)->first();

        $browser->click('@cliente');

        $browser->pause(1000);

        $browser->click('#select_client_vender');

        $browser->pause(1000);

        $browser->type('#select_client_vender-search-modal-input', $client_name)
                
                ->pause(500)

                ->keys('#select_client_vender-search-modal-input', ['{CONTROL}'])
                // ->click('@btn_search')

                ->pause(500)

                ->keys('#select_client_vender-search-modal-input', ['{ENTER}']);

        
        return $browser;
    }
}
