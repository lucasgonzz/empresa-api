<?php

namespace Tests\Browser\Listado\Helpers;

use App\Models\Address;


class StockHelper {

    static function stock_global($browser, $data) {

        $btn_stock = "#table-article tbody tr:nth-child({$data['fila']}) #btn_asignar_stock";

        $browser->waitFor($btn_stock);
        $browser->click($btn_stock);
        $browser->pause(1000);

        $browser->type('#stock-movement-amount', $data['stock']);
        $browser->pause(1000);

        if (isset($data['provider_name'])) {

            $browser->click('#stock-movement-search-povider');
            $browser->pause(1000);

            $browser->type('#stock-movement-search-povider-search-modal-input', $data['provider_name']);
            $browser->keys('#stock-movement-search-povider-search-modal-input', ['{CONTROL}']);
            $browser->pause(1000);
            $browser->keys('#stock-movement-search-povider-search-modal-input', ['{ENTER}']);

            $browser->type('@stock-movement-observations', 'Observaciones al crear');
            $browser->pause(1000);
        }

        $browser->click('@btn_guardar_stock_movement');
        $browser->pause(2000);
    }


    function stock_depositos($browser, $data) {


        $browser->script("
            let btn = document.querySelector('#table-article tbody tr:nth-child({$data['fila']}) [dusk=\"btn_editar_depositos\"]');
            let event = new MouseEvent('click', { bubbles: false });
            btn.dispatchEvent(event);
        ");

        $browser->pause(1000);

        $browser->script("document.querySelector('.cont-table').scrollLeft += 800;");
        $browser->pause(1000);

        foreach ($data['addresses'] as $address) {
            
            $address_model = Address::where('street', $address['street'])->first();

            // $input_address = '@'.$data['article_name'].'-'.$address_model->id;
            $input_address = '#input-address-stock-'.$address_model->id;

            $browser->type($input_address, $address['amount']);
            $browser->pause(1000);
        }

        $browser->click('@btn_guardar_depositos');
    
    }

    static function agregar_stock_global($browser) {

    }

}
