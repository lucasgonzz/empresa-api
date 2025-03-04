<?php

namespace Tests\Browser\Listado\Clases;

use Tests\Browser\Listado\Helpers\StockHelper;
use Tests\Browser\Listado\Helpers\StockMovementHelper;

class asignar_stock_depositos {

    function __construct($browser) {

        $this->browser = $browser;

        $this->asignar_stock_depositos();

        $this->check_stock_movement_depositos();

    }

    function asignar_stock_depositos() {
        dump('asignar_stock_depositos');
        $data = [
            'fila'          => 1,
            'article_name'  => 'Stock depositos',
            'addresses'     => [
                [
                    'street'    => 'Mar del plata',
                    'amount'    => 50,
                ],
                [
                    'street'    => 'Buenos aires',
                    'amount'    => 50,
                ],
            ],
        ];
        StockHelper::stock_depositos($this->browser, $data);

        $this->browser->pause(1000);

    }

    function check_stock_movement_depositos() {
        $data = [
            'fila'              => 1,
            'fila_movimiento'   => 2,
            'concepto'          => 'Creacion de deposito',
            'cantidad'          => 50,
            'stock_resultante'  => 50,
        ];
        StockMovementHelper::check_movement($this->browser, $data);

        $data = [
            'fila'              => 1,
            'fila_movimiento'   => 1,
            'concepto'          => 'Creacion de deposito',
            'cantidad'          => 50,
            'stock_resultante'  => 100,
        ];
        StockMovementHelper::check_movement($this->browser, $data);
    }
    
}
