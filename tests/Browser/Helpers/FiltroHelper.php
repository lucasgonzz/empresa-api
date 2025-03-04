<?php

namespace Tests\Browser\Helpers;


class FiltroHelper
{
    
    static function filtrar($browser, $data) {

        dump('Por filtrar');

        $btn_filter = "#btn_filter_{$data['prop_key']}";
        $browser->waitFor($btn_filter);
        $browser->click($btn_filter);

        $browser->pause(500);

        if (isset($data['mayor_que'])) {

            $input = "#{$data['prop_key']}_mayor_que";
            $browser->waitFor($input, 10);
            $browser->type($input, $data['mayor_que']);
        }

        if (isset($data['igual_que'])) {

            $input = "#{$data['prop_key']}_igual_que";
            $browser->waitFor($input, 10);
            $browser->type($input, $data['igual_que']);
        }

        if (isset($data['menor_que'])) {

            $input = "#{$data['prop_key']}_menor_que";
            $browser->waitFor($input, 10);
            $browser->type($input, $data['menor_que']);
        }

        $browser->pause(500);

        $btn_filtrar = "#{$data['prop_key']}_btn_filtrar";
        $browser->click($btn_filtrar);

        $browser->pause(1000);

    }

}
