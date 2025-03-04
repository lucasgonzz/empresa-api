<?php

namespace Tests\Browser\Listado\Helpers;

use Tests\Browser\Helpers\FiltroHelper;

class ListadoHelper {

    static function actualizar_listado($browser) {

        FiltroHelper::filtrar($browser, [
            'prop_key'      => 'num',
            'mayor_que'     => 0,
        ]);
    }
    
}
