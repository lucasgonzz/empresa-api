<?php

namespace Tests\Browser\Helpers;

class TableHelper {

    static function click_fila($browser, $data) {

        $browser->pause(500);
        $tr = "#table-{$data['model_name']} tbody tr:nth-child({$data['fila']})";

        $browser->waitFor($tr);
        $browser->click($tr);
        $browser->pause(500);
    }
    
}
