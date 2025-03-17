<?php

namespace Tests\Browser\Vender\Clientes\Feito\Clases;

use Tests\Browser\Helpers\FiltroHelper;
use Tests\Browser\Helpers\TableHelper;
use Tests\Browser\Listado\Helpers\StockMovementHelper;

class eliminar_venta {

    public function __construct($browser) {
        
        $this->browser = $browser;

        $this->eliminar_venta();

        $this->check_stock_movements();

        $this->check_depositos();
    }

    function check_depositos() {

        // Filtro para actualizar lista de articulos
        FiltroHelper::filtrar($this->browser, [
            'prop_key'      => 'num',
            'mayor_que'     => 1,
        ]);

        $this->browser->pause(1000);

        TableHelper::check_cell_value($this->browser, [
            'model_name'    => 'article',
            'fila'  => [
                'text'  => 'Nombre',
                'value' => 'Mate Torpedo'
            ],
            'celdas_para_chequear' => [
                'Mar del Plata' => 15,
                'Buenos Aires'  => 50,
                'Tucuman'       => 15,
                'Santa Fe'      => 10,
            ],
        ]);
    }

    function check_stock_movements() {
        $this->browser->visit('/listado-de-articulos');

        $this->browser->pause(3000);
        
        $data = [
            'fila'              => [
                'text'  => 'Nombre',
                'value' => 'Mate Torpedo',
            ],
            'fila_movimiento'   => 2,
            'concepto'          => 'Se elimino la venta',
            'cantidad'          => '10',
            'stock_resultante'  => '70',
            'deposito_destino'   => 'Tucuman',
        ];

        StockMovementHelper::check_movement($this->browser, $data);

        $data = [
            'fila'              => [
                'text'  => 'Nombre',
                'value' => 'Mate Torpedo',
            ],
            'fila_movimiento'   => 1,
            'concepto'          => 'Se elimino la venta',
            'cantidad'          => '20',
            'stock_resultante'  => '90',
            'deposito_destino'   => 'Tucuman',
        ];

        StockMovementHelper::check_movement($this->browser, $data);
    }

    function eliminar_venta() {
        $this->browser->visit('/ventas/todas/todos');

        $this->browser->pause(3000);

        TableHelper::click_fila($this->browser, [
            'model_name'    => 'sale',
            'fila'          => [
                'text'  => 'Total',
                'value' => '$53.000'
            ],
        ]);

        $this->browser->pause(1000);

        $btn_eliminar = "@btn_eliminar_sale";
        $this->browser->waitFor($btn_eliminar);
        $this->browser->click($btn_eliminar);
        $this->browser->pause(2000);
    }

}
