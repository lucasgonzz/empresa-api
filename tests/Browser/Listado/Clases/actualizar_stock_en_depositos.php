<?php

namespace Tests\Browser\Listado\Clases;

use Tests\Browser\Listado\Helpers\StockHelper;
use Tests\Browser\Listado\Helpers\StockMovementHelper;

class actualizar_stock_en_depositos {

    function __construct($browser) {

        $this->browser = $browser;

        $this->actualizar_stock_en_depositos();

        $this->check_stock_movement_actualizacion_depositos();
    }

    function actualizar_stock_en_depositos() {
        dump('actualizar_stock_en_depositos');

        $this->browser->pause(500);

        $data = [
            'fila'          => 2,
            'article_name'  => 'Stock global',
            'addresses'     => [
                [
                    'street'    => 'Mar del plata',
                    'amount'    => 50,
                ],
                [
                    'street'    => 'Buenos aires',
                    'amount'    => 150,
                ],
            ],
        ];
        StockHelper::stock_depositos($this->browser, $data);

        dump('Depositos actualizados');

        $this->browser->pause(1000);

    }

    function check_stock_movement_actualizacion_depositos() {
        $data = [
            'fila'              => 2,
            'fila_movimiento'   => 2,
            'concepto'          => 'Actualizacion de deposito',
            'cantidad'          => 50,
            'stock_resultante'  => 250,
        ];
        StockMovementHelper::check_movement($this->browser, $data);

        $data = [
            'fila'              => 2,
            'fila_movimiento'   => 1,
            'concepto'          => 'Actualizacion de deposito',
            'cantidad'          => -50,
            'stock_resultante'  => 200,
        ];
        StockMovementHelper::check_movement($this->browser, $data);
    }
    
}
