<?php

namespace Tests\Browser\Vender\Clases;

use Tests\Browser\Helpers\CurrentAcountHelper;
use Tests\Browser\Helpers\FormHelper;
use Tests\Browser\Vender\Helpers\ClientHelper;
use Tests\Browser\Vender\Helpers\RemitoHelper;
use Tests\Browser\Vender\Helpers\VenderHelper;

class venta_cliente_omitir_cc {

    public $client_name = 'Jorge nuevo';

    function __construct($browser) {

        $this->browser = $browser;

        $this->browser->visit('/vender/remito');
        $this->browser->pause(2000);

        $this->agregar_articulo();
        
        $this->asignar_direccion();

        $this->asignar_cliente();

        $this->omitir_en_cc();

        $this->guardar_venta();

        $this->check_client_saldo();

    }

    /*
        Chequeo que, al no crearse movimienot, el saldo sea 0
    */
    function check_client_saldo() {

        $this->browser->visit('clientes/clientes');
        $this->browser->pause(1000);

        FormHelper::check_prop_value($this->browser, [
            'model_name'    => 'client',
            'fila'          => 1,
            'key'           => 'saldo',
            'value'         => '-',
        ]);

    }

    function guardar_venta() {
        VenderHelper::btn_guardar($this->browser);
    }

    function omitir_en_cc() {
        ClientHelper::omitir_en_cc($this->browser);
    }

    function asignar_cliente() {
        ClientHelper::select_client($this->browser, $this->client_name);

        $this->browser->pause(500);
    }

    function agregar_articulo() {

        RemitoHelper::add_article_bar_code($this->browser, '002', 10);

        $this->browser->pause(500);
        
        RemitoHelper::check_total($this->browser, '$40.000');
    }

    function asignar_direccion() {

        VenderHelper::set_address($this->browser, 'Buenos Aires');

        $this->browser->pause(500);
    }
    
}
