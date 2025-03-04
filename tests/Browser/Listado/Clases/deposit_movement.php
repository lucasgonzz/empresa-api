<?php

namespace Tests\Browser\Listado\Clases;

use Tests\Browser\Listado\Helpers\DepositMovementHelper;
use Tests\Browser\Listado\Helpers\StockMovementHelper;

class deposit_movement {

    function __construct($browser) {

        $this->browser = $browser;

        $this->deposit_movement();

        $this->check_stock_deposit_movement();
    }
 
    function deposit_movement() {
        dump('deposit_movement');
        $this->browser->pause(500);

        DepositMovementHelper::crear_movimiento($this->browser, [
            'deposito_origen'   => 'Santa Fe',
            'deposito_destino'  => 'Tucuman',
            'estado'            => 'Recibido',
            'articles'          => [
                [
                    'name'      => 'Stock global',
                    'amount'    => 10,
                ],
                [
                    'name'      => 'Stock depositos',
                    'amount'    => 20,
                ],
            ],
        ]);
        
        $this->browser->pause(500);

    }

    function check_stock_deposit_movement() {
        $data = [
            'fila'              => 2,
            'fila_movimiento'   => 1,
            'concepto'          => 'Mov entre depositos',
            'cantidad'          => 10,
            'stock_resultante'  => 200,
            'deposito_origen'   => 'Santa Fe',
            'deposito_destino'  => 'Tucuman',
        ];
        StockMovementHelper::check_movement($this->browser, $data);

        $data = [
            'fila'              => 1,
            'fila_movimiento'   => 1,
            'concepto'          => 'Mov entre depositos',
            'cantidad'          => 20,
            'stock_resultante'  => 100,
            'deposito_origen'   => 'Santa Fe',
            'deposito_destino'  => 'Tucuman',
        ];
        StockMovementHelper::check_movement($this->browser, $data);

    }
    
}
