<?php

namespace Tests\Browser\Helpers;


class SeleccionHelper
{
    
    static function seleccionar($browser, $data) {

        $browser->click('#btn_seleccion');

        $browser->pause(500);

        foreach ($data['filas'] as $fila) {
            
            $table_row = "#table-{$data['model_name']} tbody tr:nth-child({$fila}) td:nth-child(1)";

            $browser->click($table_row);    
            $browser->pause(500);
        }

    }

    static function seleccionar_opcion($browser, $data) {

        $browser->click('#btn_seleccionados_dropdown');

        $browser->pause(500);

        $browser->click($data['btn']);

        if (isset($data['increment'])) {

            $input = "#increment_".$data['increment']['prop_key'];
    
            $browser->type($input, $data['increment']['value']);
            $browser->pause(500);
        }

        if (isset($data['set'])) {

            $input = "#set_".$data['set']['prop_key'];
    
            $browser->type($input, $data['set']['value']);
            $browser->pause(500);
        }
        
        $browser->pause(1500);

        $browser->click('#btn_send_actualizar');
        $browser->pause(1000);

    }

    static function regresar($browser, $data) {

        $browser->click('#btn_restart_filter');
        $browser->pause(500);
    }

    static function desactivar_seleccion($browser) {

        $browser->click('#btn_seleccion');

    }


}
