<?php

namespace Tests\Browser\Listado\Clases;

use Tests\Browser\Listado\Helpers\StockHelper;
use Tests\Browser\Listado\Helpers\StockMovementHelper;

class asignar_stock_global {

    function __construct($browser) {

        $this->browser = $browser;

        $this->asignar_stock_global();

        $this->check_stock_movement_global();

        $this->sumar_stock();

        $this->restar_stock();

    }

    function asignar_stock_global() {
        $data = [
            'fila'  => 2,
            'stock' => 100,
            'provider_name' => 'rosario',
        ];
        StockHelper::stock_global($this->browser, $data);

        $this->browser->pause(1000);
    }

    function check_stock_movement_global() {
        $data = [
            'fila'              => 2,
            'fila_movimiento'   => 1,
            'concepto'          => 'Ingreso manual',
            'cantidad'          => '100.00',
            'stock_resultante'  => '100.00',
        ];

        StockMovementHelper::check_movement($this->browser, $data);
    }

    function sumar_stock() {

        StockHelper::stock_global($this->browser, [
            'fila'  => 2,
            'stock' => 10,
        ]);

        $this->browser->pause(500);

        $data = [
            'fila'              => 2,
            'fila_movimiento'   => 1,
            'concepto'          => 'Ingreso manual',
            'cantidad'          => 10,
            'stock_resultante'  => 110,
        ];
        StockMovementHelper::check_movement($this->browser, $data);
    }

    function restar_stock() {
        
        StockHelper::stock_global($this->browser, [
            'fila'  => 2,
            'stock' => -20,
        ]);

        $this->browser->pause(500);

        $data = [
            'fila'              => 2,
            'fila_movimiento'   => 1,
            'concepto'          => 'Ingreso manual',
            'cantidad'          => -20,
            'stock_resultante'  => 90,
        ];
        StockMovementHelper::check_movement($this->browser, $data);
    }
    
}
