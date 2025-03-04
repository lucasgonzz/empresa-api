<?php

namespace Tests\Browser\Vender\Helpers;

class ActualizarVentaHelper {

    static function actualizar_venta($browser, $data) {

        Self::seleccionar_venta($browser, $data);

        $browser->waitFor('@btn_actualizar_venta');
        $browser->click('@btn_actualizar_venta');
        
        $browser->waitFor('@total');
        $browser->pause(2000);

    }

    static function seleccionar_venta($browser, $data) {
        
        $browser->visit('/ventas/todas/todos');

        $browser->pause(2000);

        $browser->waitFor("#table-sale");

        $browser->click("#table-sale tbody tr:nth-child({$data['fila']})");

        $browser->pause(500);
    }
    
}
