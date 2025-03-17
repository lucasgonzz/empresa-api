<?php

namespace Tests\Browser\Listado\Helpers;

use App\Models\Address;
use Tests\Browser\Helpers\TableHelper;


class StockMovementHelper {

    static function check_movement($browser, $data) {

        $fila = null;

        if (is_array($data['fila'])) {

            $table_name = "#table-article";
            $fila_title = $data['fila']['text'];
            $fila_value = $data['fila']['value'];
            
            $fila = TableHelper::get_fila_a_chequear($browser, $table_name, $fila_title, $fila_value);

            $fila++;

        } else if (is_numeric($data['fila'])) {
            $fila = $data['fila'];
        }

        $btn = "#table-article tbody tr:nth-child($fila) .btn-stock-movement";

        $browser->waitFor($btn);

        $browser->script("
            let btn = document.querySelector('#table-article tbody tr:nth-child($fila) [dusk=\"btn-stock-movements\"]');
            let event = new MouseEvent('click', { bubbles: false });
            btn.dispatchEvent(event);
        ");


        $browser->waitFor('#stock-movement-table', 60);

        $browser->pause(1000);

        // Chequeo el concepto 
        $td_concepto = "#stock-movement-table tbody tr:nth-child({$data['fila_movimiento']}) td:nth-child(1)";
        $browser->assertSeeIn($td_concepto, $data['concepto']);


        // Chequeo la cantidad 
        $td_cantidad = "#stock-movement-table tbody tr:nth-child({$data['fila_movimiento']}) td:nth-child(3)";
        $browser->assertSeeIn($td_cantidad, $data['cantidad']);


        // Chequeo el stock resultante 
        $td_stock_resultante = "#stock-movement-table tbody tr:nth-child({$data['fila_movimiento']}) td:nth-child(5)";
        $browser->assertSeeIn($td_stock_resultante, $data['stock_resultante']);

        
        // Chequeo deposito origen 
        if (isset($data['deposito_origen'])) {

            $td_deposito_origen = "#stock-movement-table tbody tr:nth-child({$data['fila_movimiento']}) td:nth-child(7)";
            $browser->assertSeeIn($td_deposito_origen, $data['deposito_origen']);
        }
        // Chequeo deposito destino 
        if (isset($data['deposito_destino'])) {

            $td_deposito_destino = "#stock-movement-table tbody tr:nth-child({$data['fila_movimiento']}) td:nth-child(8)";
            $browser->assertSeeIn($td_deposito_destino, $data['deposito_destino']);
        }

        $browser->click('#stock-movement-modal-info .close');

        $browser->pause(1000);

        dump('Movimiento stock ok');

    }

}
