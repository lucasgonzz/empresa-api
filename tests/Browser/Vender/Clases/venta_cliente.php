<?php

namespace Tests\Browser\Vender\Clases;

use Tests\Browser\Helpers\CurrentAcountHelper;
use Tests\Browser\Listado\Helpers\StockMovementHelper;
use Tests\Browser\Vender\Helpers\ActualizarVentaHelper;
use Tests\Browser\Vender\Helpers\ClientHelper;
use Tests\Browser\Vender\Helpers\DiscountHelper;
use Tests\Browser\Vender\Helpers\RemitoHelper;
use Tests\Browser\Vender\Helpers\VenderHelper;

class venta_cliente {

    /*
        * Creo venta con:
            1 articulo
            1 servicio
        * Asigno cliente "Lucas gonzalez"
    */

    function __construct($browser) {

        $this->browser = $browser;

        $this->browser->visit('/vender/remito');
        $this->browser->pause(2000);

        $this->agregar_articulo();

        $this->agregar_servicio();

        $this->set_address();

        $this->agregar_descuento();

        $this->agregar_recargo();

        $this->aplicar_descuentos_a_servicios();

        $this->agregar_cliente();

        $this->guardar_venta();

        $this->check_client_current_acount('$4.625');

        $this->actualizar_venta();

        $this->check_articles_stock();

        $this->check_client_current_acount('$4.875');

    }

    function check_client_current_acount($monto) {
        new CurrentAcountHelper($this->browser, [
            'client_name'   => 'Lucas Gonzalez',
            'fila'          => 1,
            'debe'          => $monto,
            'saldo'         => $monto,
        ]);
    }

    function set_address() {

        VenderHelper::set_address($this->browser, 'Buenos Aires');

        $this->browser->pause(500);
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
            'stock_resultante'  => '190',
            'deposito_origen'   => 'Buenos Aires',
        ];

        StockMovementHelper::check_movement($this->browser, $data);
    }

    function actualizar_venta() {

        $this->seleccionar_venta();

        $this->aplicar_recargos_a_servicios();

        $this->guardar_venta();
    }

    function seleccionar_venta() {
        
        ActualizarVentaHelper::actualizar_venta($this->browser, [
            'fila'  => 1,
        ]);

    }

    function guardar_venta() {
        VenderHelper::btn_guardar($this->browser);
    }

    function aplicar_recargos_a_servicios() {

        DiscountHelper::aplicar_recargos_a_servicios($this->browser, true, 'Lucas Gonzalez');

        RemitoHelper::check_total($this->browser, '$4.875');

        $this->browser->pause(1000);
    }

    function aplicar_descuentos_a_servicios() {

        DiscountHelper::aplicar_descuentos_a_servicios($this->browser, false);

        RemitoHelper::check_total($this->browser, '$4.625');

        $this->browser->pause(1000);
    }

    function agregar_recargo() {

        DiscountHelper::add_surchage($this->browser, 'Envio', false);

        RemitoHelper::check_total($this->browser, '$5.125');
    }

    function agregar_descuento() {

        DiscountHelper::add_discount($this->browser, 'Efectivo');

        RemitoHelper::check_total($this->browser, '$3.750');
    }

    function agregar_cliente() {
        ClientHelper::select_client($this->browser, 'Lucas gonzalez');

        $this->browser->pause(500);
    }

    function agregar_articulo() {

        RemitoHelper::add_article_bar_code($this->browser, '1234', 5);

        $this->browser->pause(500);
        
        RemitoHelper::check_total($this->browser, '$5.500');
    }

    function agregar_servicio() {

        RemitoHelper::add_service($this->browser, [
            'name'  => 'Envio',
            'price' => 1000
        ]);

        RemitoHelper::check_total($this->browser, '$6.500');
    }
    
}
