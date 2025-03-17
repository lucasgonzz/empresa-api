<?php

namespace Tests\Browser\Vender\Clientes\Feito\Clases;

use Tests\Browser\Helpers\FiltroHelper;
use Tests\Browser\Helpers\TableHelper;
use Tests\Browser\Listado\Helpers\StockMovementHelper;
use Tests\Browser\Vender\Helpers\AfipHelper;
use Tests\Browser\Vender\Helpers\ClientHelper;
use Tests\Browser\Vender\Helpers\PaymentMethodsHelper;
use Tests\Browser\Vender\Helpers\RemitoHelper;
use Tests\Browser\Vender\Helpers\VenderHelper;

class crear_venta {

    public function __construct($browser) {
        
        $this->browser = $browser;

        $this->agregar_articulos();

        $this->cambiar_cantidades();

        // Cambio metodos de pago y chequeo los totales
        $this->cambiar_payment_methods();


        /*
            Agrego metodos de pago, algunos con descuentos
            Y vuelvo a repartir el nuevo total con descuento 
        */ 
        $this->set_multiples_payment_methods();

        $this->signar_cliente_por_cuit();

        $this->browser->click('@remito');

        $this->set_address();

        $this->set_facturar();

        $this->guardar_venta();

        $this->browser->pause(5000);

        $this->check_total_facturado();

        $this->check_articles_stock();

    }

    function check_articles_stock() {

        $this->browser->visit('/listado-de-articulos');
        $this->browser->pause(6000);

        $data = [
            'fila'              => [
                'text'  => 'Nombre',
                'value' => 'Mate Torpedo',
            ],
            'fila_movimiento'   => 2,
            'concepto'          => 'Venta',
            'cantidad'          => '-10',
            'stock_resultante'  => '80',
            'deposito_origen'   => 'Tucuman',
        ];

        StockMovementHelper::check_movement($this->browser, $data);


        $data = [
            'fila'              => [
                'text'  => 'Nombre',
                'value' => 'Mate Torpedo',
            ],
            'fila_movimiento'   => 1,
            'concepto'          => 'Venta',
            'cantidad'          => '-20',
            'stock_resultante'  => '60',
            'deposito_origen'   => 'Tucuman',
        ];

        StockMovementHelper::check_movement($this->browser, $data);


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
                'Tucuman'       => -15,
                'Santa Fe'      => 10,
            ],
        ]);
    }

    function check_total_facturado() {
        $this->browser->visit('/ventas/todas/todos');
        $this->browser->pause(1000);
        TableHelper::check_cell_value($this->browser, [
            'model_name'    => 'sale',
            'fila'          => [
                'text'  => 'Total',
                'value' => '$53.000',
            ],
            'celdas_para_chequear'  => [
                'Total Facturado'   => '$40.000',
            ],
        ]);
        $this->browser->pause(3000);
    }

    function guardar_venta() {
        VenderHelper::btn_guardar($this->browser);
    }

    function set_facturar() {
        AfipHelper::set_punto_de_venta($this->browser, [
            'afip_information_id'           => 1,
            'afip_tipo_comprobante_name'    => 'Factura A',
        ]);
        $this->browser->pause(500);
    }

    function set_address() {

        $this->browser->pause(500);

        VenderHelper::set_address($this->browser, 'Tucuman');
    }


    function signar_cliente_por_cuit() {
        
        $cuit = "20175018841";
        ClientHelper::buscar_cliente_por_cuit($this->browser, [
            'cuit'                  => $cuit,
            'check_nombre_cliente'  => 'GREGORIO JESUS',
        ]); 
    }

    function set_multiples_payment_methods() {

        PaymentMethodsHelper::set_payment_methods($this->browser, [
            'payment_methods'   => [
                [
                    'name'      => 'Transferencia',
                    'amount'    => 20000,
                ],
                [
                    'name'      => 'Credito',
                    'amount'    => 20000,
                ],
                [
                    'name'      => 'Efectivo',
                ],
            ],
            'calcular' => [
                'nuevo_total_a_chequear'    => '$53.000',
                'nuevos_payment_methods'    => [
                    [
                        'name'      => 'Credito',
                        'amount'    => 20000 
                    ],
                    [
                        'name'      => 'Transferencia',
                        'amount'    => 20000 
                    ],
                    [
                        'name'      => 'Efectivo',
                    ],
                ],
            ]
        ]);

        $this->browser->pause(1000);
    }

    function cambiar_payment_methods() {
        VenderHelper::set_payment_method($this->browser, 'Credito');
        RemitoHelper::check_total($this->browser, '$60.000');


        VenderHelper::set_payment_method($this->browser, 'Transferencia');
        RemitoHelper::check_total($this->browser, '$51.000');


        VenderHelper::set_payment_method($this->browser, 'Efectivo');
        RemitoHelper::check_total($this->browser, '$48.000');
    }

    function cambiar_cantidades() {
        RemitoHelper::cambiar_amount($this->browser, [
            'index'         => 0,
            'new_amount'    => 10, 
        ]);

        RemitoHelper::cambiar_amount($this->browser, [
            'index'         => 1,
            'new_amount'    => 20, 
        ]);

        RemitoHelper::check_total($this->browser, '$48.000');
    }

    function agregar_articulos() {

        // Busco el codigo de la variante "Amarillo XL"
        RemitoHelper::add_article_bar_code($this->browser, '07');

        $this->browser->pause(1000);

        // Busco el codigo de la variante "Azul XL"
        RemitoHelper::add_article_bar_code($this->browser, '05');

        $this->browser->pause(1000);

        RemitoHelper::check_total($this->browser, '$3.200');
    }

}
