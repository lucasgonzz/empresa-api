<?php

namespace Tests\Browser\Listado\Clases;

use Tests\Browser\Listado\Helpers\StockHelper;
use Tests\Browser\Listado\Helpers\StockMovementHelper;

class divir_stock_en_depositos {

    function __construct($browser) {

        $this->browser = $browser;

        $this->divir_stock_en_depositos();

        $this->check_stock_movement_creacion_depositos();
    }


    function divir_stock_en_depositos() {

        $this->browser->pause(500);

        $data = [
            'fila'          => 2,
            'article_name'  => 'Stock global',
            'addresses'     => [
                [
                    'street'    => 'Mar del plata',
                    'amount'    => 100,
                ],
                [
                    'street'    => 'Buenos aires',
                    'amount'    => 100,
                ],
            ],
        ];
        StockHelper::stock_depositos($this->browser, $data);

        dump('Stock global dividido en depositos');

        $this->browser->pause(1000);

    }

    function check_stock_movement_creacion_depositos() {
        $data = [
            'fila'              => 2,
            'fila_movimiento'   => 2,
            'concepto'          => 'Creacion de deposito',
            'cantidad'          => 100,
            'stock_resultante'  => 100,
        ];
        StockMovementHelper::check_movement($this->browser, $data);

        $data = [
            'fila'              => 2,
            'fila_movimiento'   => 1,
            'concepto'          => 'Creacion de deposito',
            'cantidad'          => 100,
            'stock_resultante'  => 200,
        ];
        StockMovementHelper::check_movement($this->browser, $data);
    }
    
}
