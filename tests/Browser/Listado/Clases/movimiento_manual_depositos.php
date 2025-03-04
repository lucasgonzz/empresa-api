<?php

namespace Tests\Browser\Listado\Clases;

use Tests\Browser\Listado\Helpers\MovimientoDepositosHelper;
use Tests\Browser\Listado\Helpers\StockMovementHelper;

class movimiento_manual_depositos {

    function __construct($browser) {

        $this->browser = $browser;

        $this->movimiento_manual_depositos();
        
        $this->check_stock_movement_movimiento_depositos();

    }

    function movimiento_manual_depositos() {
        dump('movimiento_manual_depositos');

        MovimientoDepositosHelper::mover_stock($this->browser, [
            'fila'      => 1,
            'from'      => 'Mar del plata',
            'to'        => 'Santa Fe',
            'amount'    => 10,
        ]);

        
    }

    function check_stock_movement_movimiento_depositos() {
        $data = [
            'fila'              => 1,
            'fila_movimiento'   => 1,
            'concepto'          => 'Mov manual entre depositos',
            'cantidad'          => 10,
            'stock_resultante'  => 100,
            'deposito_origen'   => 'Mar del Plata',
            'deposito_destino'  => 'Santa Fe',
        ];
        StockMovementHelper::check_movement($this->browser, $data);

    }
    
}
