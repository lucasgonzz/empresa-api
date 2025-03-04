<?php

namespace Tests\Browser\Listado\Clases;

use Tests\Browser\Listado\Helpers\ActualizarRepetidoHelper;

class act_articulo_repetido {

    function __construct($browser) {

        $this->browser = $browser;

        $this->articulo_repetido();

    }

    function articulo_repetido() {

        dump('articulo_repetido');
        ActualizarRepetidoHelper::actualizar_repetido($this->browser, [
            'bar_code'      => 12345,
            'cost'          => 2000,
            'check'         => [
                'key'   => 'final_price',
                'value' => '$4.000',
            ],
        ]);
    }
    
}
