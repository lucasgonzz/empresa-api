<?php

namespace Tests\Browser\Listado\Clases;

use Tests\Browser\Listado\Helpers\CrearArticuloHelper;



class crear_articulos {

    function __construct($browser) {
        $this->browser = $browser;

        $this->crear_stock_global();

        $this->browser->pause(1000);

        $this->crear_stock_depositos();
    }

    function crear_stock_global() {

        $articulo_para_crear = [
            'props' => [
                [
                    'key'   => 'bar_code',
                    'value' => '1234',
                ],
                [
                    'key'   => 'name',
                    'value' => 'Stock global',
                ],
                [
                    'key'   => 'cost',
                    'value' => 1000,
                ],
                [
                    'key'   => 'percentage_gain',
                    'value' => 10,
                ],
                [
                    'key'   => 'provider_id',
                    'value' => 'Rosa',
                    'type'  => 'search',
                ],
            ],
            'props_to_check' => [
                [
                    'key'   => 'final_price',
                    'value' => '$1.100,00',
                ],
            ],
        ];

        CrearArticuloHelper::crear_articulo($this->browser, $articulo_para_crear);
        
    }

    function crear_stock_depositos() {

        $articulo_para_crear = [
            'props' => [
                [
                    'key'   => 'bar_code',
                    'value' => '12345',
                ],
                [
                    'key'   => 'name',
                    'value' => 'Stock depositos',
                ],
                [
                    'key'   => 'cost',
                    'value' => 1000,
                ],
                [
                    'key'   => 'provider_id',
                    'value' => 'Buenos',
                    'type'  => 'search',
                ],
            ],
            'props_to_check' => [
                [
                    'key'   => 'final_price',
                    'value' => '$2.000',
                ],
            ],
        ];

        CrearArticuloHelper::crear_articulo($this->browser, $articulo_para_crear);
        
    }


    
}
