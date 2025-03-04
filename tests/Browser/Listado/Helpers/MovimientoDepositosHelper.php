<?php

namespace Tests\Browser\Listado\Helpers;

use App\Models\Address;


class MovimientoDepositosHelper {

    static function mover_stock($browser, $data) {
        
        $browser->pause(500);

        $browser->script("
            let btn = document.querySelector('#table-article tbody tr:nth-child({$data['fila']}) [dusk=\"btn-movimiento-depositos\"]');
            let event = new MouseEvent('click', { bubbles: false });
            btn.dispatchEvent(event);
        ");

        $browser->pause(500);

        $from_address = Address::where('street', $data['from'])->first();
        $browser->select('#from_address_id', $from_address->id);

        $browser->pause(500);

        $to_address = Address::where('street', $data['to'])->first();
        $browser->select('#to_address_id', $to_address->id);

        $browser->pause(500);

        $browser->type('#amount', $data['amount']);

        $browser->pause(500);

        $browser->waitFor('@btn_guardar_movimiento_deposito');
        $browser->click('@btn_guardar_movimiento_deposito');

        $browser->pause(1000);
    }
}
