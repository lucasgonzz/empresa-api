<?php

namespace Tests\Browser\Vender\Clases;

use Tests\Browser\Helpers\ToastHelper;
use Tests\Browser\Listado\Helpers\StockMovementHelper;
use Tests\Browser\Vender\Helpers\DiscountHelper;
use Tests\Browser\Vender\Helpers\PaymentMethodsHelper;
use Tests\Browser\Vender\Helpers\RemitoHelper;
use Tests\Browser\Vender\Helpers\VenderHelper;


class crear_venta {

    /*
        * Creo venta con
        * 2 articulos
        * Total forzado
        * Descuentos y Recargos
        * Multiple Metodo de Pago
        * Agrego nuevo descuento para que no me haga volver a indicar
        el metodo de pago
        * Indico Metodos de pago
        * Guardo venta
        * Chequeo movimientos de stock
    */

    function __construct($browser) {

        $this->browser = $browser;

        // $this->check_articles_stock();

        // return;

        $browser->pause(2000);

        $this->agregar_articulos();

        $this->forzar_total();

        $this->agregar_descuentos_y_recargos();

        $this->metodos_de_pago();

        $this->set_address();

        $this->agregar_nuevo_descuento();

        /*
            Intento guardar venta
            Pero como aplique otro descuento, reicibo error de indicar
            metodo de pago
        */
        $this->intentar_guardar_venta();

        $this->vuelver_a_indicar_metodos_de_pago();

        $this->guardar_venta();

        $this->check_articles_stock();

        // $this->check_reportes();

        $browser->pause(5000);
    }

    function guardar_venta() {
        VenderHelper::btn_guardar($this->browser);
    }

    function check_reportes() {
        $this->browser->pause(2000);

        $this->browser->visit('/reportes/generales');

        $this->browser->pause(2000);

        $this->browser->click('@graficos');

        $this->browser->assertSee('$5.000');
        $this->browser->assertSee('$9.368,75');

        dump('Reportes OK');
    }

    function intentar_guardar_venta() {

        VenderHelper::btn_guardar($this->browser);
        ToastHelper::check_toast($this->browser, 'Seleccione Metodo de Pago');
    }

    function agregar_nuevo_descuento() {

        DiscountHelper::add_discount($this->browser, 'Placas');
        RemitoHelper::check_total($this->browser, '$14.368,75');

    }

    function set_address() {

        VenderHelper::set_address($this->browser, 'Tucuman');
    }

    function check_articles_stock() {

        $this->browser->visit('/listado-de-articulos');

        $this->browser->pause(4000);

        // Chequeo stock de "Stock global"
        $data = [
            'fila'              => 2,
            'fila_movimiento'   => 1,
            'concepto'          => 'Venta',
            'cantidad'          => '5',
            'stock_resultante'  => '195',
            'deposito_origen'   => 'Tucuman',
        ];

        StockMovementHelper::check_movement($this->browser, $data);

        // Chequeo stock de "Stock depositos"
        $data = [
            'fila'              => 2,
            'fila_movimiento'   => 1,
            'concepto'          => 'Venta',
            'cantidad'          => '5',
            'stock_resultante'  => '95',
            'deposito_origen'   => 'Tucuman',
        ];

        StockMovementHelper::check_movement($this->browser, $data);
    }

    function vuelver_a_indicar_metodos_de_pago() {
        
        $this->browser->click('@remito');

        $this->browser->pause(1000);

        $this->browser->click('#btn_set_payment_methods');

        PaymentMethodsHelper::add_payment_method($this->browser, 'Debito', 5000);
        
        $this->browser->pause(500);
        
        PaymentMethodsHelper::add_payment_method($this->browser, 'Efectivo');

        $this->browser->pause(500);

        PaymentMethodsHelper::guardar($this->browser);
        
        $this->browser->pause(500);
    }

    function metodos_de_pago() {

        PaymentMethodsHelper::set_payment_methods($this->browser, [
            'payment_methods'   => [
                [
                    'name'      => 'Debito',
                    'amount'    => 5000,
                ],
                [
                    'name'      => 'Efectivo',
                ],
            ],
        ]);
        
        // $this->browser->click('@remito');

        // $this->browser->pause(1000);

        // $this->browser->click('#btn_set_payment_methods');

        // PaymentMethodsHelper::add_payment_method($this->browser, 'Debito', 5000);
        
        // $this->browser->pause(500);
        
        // PaymentMethodsHelper::add_payment_method($this->browser, 'Efectivo');

        // $this->browser->pause(500);

        // PaymentMethodsHelper::guardar($this->browser);
        
        // $this->browser->pause(500);
    }

    function agregar_descuentos_y_recargos() {

        DiscountHelper::add_discount($this->browser, 'Efectivo');
        RemitoHelper::check_total($this->browser, '$12.500');

        DiscountHelper::add_discount($this->browser, 'Contado');
        RemitoHelper::check_total($this->browser, '$11.000');

        DiscountHelper::add_discount($this->browser, 'Contado');
        RemitoHelper::check_total($this->browser, '$12.500');

        DiscountHelper::add_surchage($this->browser, 'Iva 21');
        RemitoHelper::check_total($this->browser, '$15.125');
    }

    function forzar_total() {

        RemitoHelper::forzar_total($this->browser,'25000', '$25.000');
    }

    function agregar_articulos() {

        RemitoHelper::add_article_bar_code($this->browser, '001', 5);

        $this->browser->pause(1000);

        RemitoHelper::add_article_bar_code($this->browser, '002', 5);

        $this->browser->pause(1000);

        RemitoHelper::check_total($this->browser, '$25.500');
    }
    
}
