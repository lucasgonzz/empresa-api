<?php

namespace Tests\Browser\Vender\Clases;

use Tests\Browser\Vender\Helpers\ActualizarVentaHelper;
use Tests\Browser\Vender\Helpers\PaymentMethodsHelper;
use Tests\Browser\Vender\Helpers\RemitoHelper;
use Tests\Browser\Vender\Helpers\VenderHelper;

class actualizar_metodos_de_pago {

    function __construct($browser) {

        $this->browser = $browser;

        $this->seleccionar_venta();

        $this->forzar_total();

        $this->seleccionar_metodos_de_pago();

        $this->guardar_venta();

        /* 
            Chequear en reportes:
                Mercado Pago: $30.000 
                Credito: $5.000
        */
        // $this->check_reportes();

    }

    function check_reportes() {
        // $this->browser->visit('/reportes/generales');

        $this->browser->pause(1000);

        $this->browser->click('@graficos');

        $this->browser->pause(2000);
        $data = $this->browser->script("return document.querySelector('#chart-data')?.innerText;");
        dump($data);
        $this->browser->assertSeeIn('#chart-data', 'este es el valor');
    }

    function guardar_venta() {
        VenderHelper::btn_guardar($this->browser);
    }

    function seleccionar_metodos_de_pago() {

        PaymentMethodsHelper::set_payment_methods($this->browser, [
            'payment_methods'   => [
                [
                    'name'      => 'Mercado pago',
                    'amount'    => 30000,
                ],
                [
                    'name'      => 'Credito',
                ],
            ],
        ]);
    }

    function forzar_total() {

        RemitoHelper::forzar_total($this->browser, '35000', '$35.000');
    }

    function seleccionar_venta() {
        
        ActualizarVentaHelper::actualizar_venta($this->browser, [
            'fila'  => 1,
        ]);
    }
    
}
