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

    function guardar_como_presupuesto($browser) {

        $browser->waitFor('#guardar_como_presupuesto');
        $browser->click('#guardar_como_presupuesto');
        $browser->pause('500');

    }

    function buscar_cliente_por_cuit($browser, $data) {

        $browser->click('@cliente');
        
        $browser->pause(500);

        $input = "#cuit-para-buscar";

        $browser->waitFor($input);
        $browser->type($input, $data['cuit']);
        
        $browser->keys($input, ['{ENTER}']);

        $modal_result = "#afip-data-modal";
        $browser->waitFor($modal_result);

        $browser->pause(1000);

        if ($data['check_nombre_cliente']) {
            $browser->assertSee($data['check_nombre_cliente']);
            dump('Nombre del cliente OK');
        }

        $btn = "#crear_cliente";
        $browser->waitFor($btn);
        $browser->click($btn);


        // Espero que se abra modal client y guardo al nuevo cliente

        $modal_client = "#client";
        $browser->waitFor($modal_client);
        
        $browser->pause(1000);

        $browser->click("@btn_guardar_client");

        $browser->pause(1000);


        // Selecciono el nuevo cliente desde el search modal
        $search_modal = "#select_client_vender-search-modal ";
        $browser->waitFor($search_modal);
        $browser->pause(1000);
        $browser->keys('#select_client_vender-search-modal-input', ['{ENTER}']);
        $browser->pause(1000);



    }
}
