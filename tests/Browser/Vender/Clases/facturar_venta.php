<?php

namespace Tests\Browser\Vender\Clases;

use Tests\Browser\Vender\Helpers\ActualizarVentaHelper;

class facturar_venta {

    function __construct($browser) {

        $this->browser = $browser;

        $this->seleccionar_venta();

        $this->boton_facutar();

        $this->punto_de_venta();

        $this->tipo_comprobante();

        $this->mandar_a_facturar();

        $this->check_reportes();

    }

    function check_reportes() {
        $this->browser->visit('reportes/generales');
        $this->browser->pause(2000);

        /*
            La venta tiene un total de 35.000
            Pero el iva se obtiene de los productos, asi que el total facturado 
            es de $8.400 
        */
        $monto_iva_debito = "$8.400";

        $card_iva_debito = "#iva_debito";
        $this->browser->waitFor($card_iva_debito);
        $this->browser->scrollIntoView($card_iva_debito);
        $this->browser->assertSeeIn($card_iva_debito, $monto_iva_debito);
    }

    function mandar_a_facturar() {
        $this->browser->pause(500);

        $btn = "#btn_enviar_a_facturar";
        $this->browser->click($btn);
        dump('Factura enviada');
        $this->browser->pause(3000);
    }

    function tipo_comprobante() {
        $this->browser->pause(500);

        $select = "#select_tipo_comprobante";
        $this->browser->waitFor($select);

        $browser = $this->browser;
        $this->browser->waitUntil(function ($browser) {
            return count($browser->script("return document.querySelectorAll('#select_tipo_comprobante option');")) >= 1;
        });

        // Factura B
        $tipo_comprobante_id = 2;
        $this->browser->select($select, $tipo_comprobante_id);

    }

    function punto_de_venta() {
        $this->browser->pause(500);

        $select = "#select_punto_de_venta";
        $this->browser->waitFor($select);

        $browser = $this->browser;
        $this->browser->waitUntil(function ($browser) {
            return count($browser->script("return document.querySelectorAll('#select_punto_de_venta option');")) >= 1;
        });


        $afip_information_id = 1;
        $this->browser->select($select, $afip_information_id);
    }
 
    function seleccionar_venta() {
        
        ActualizarVentaHelper::seleccionar_venta($this->browser, [
            'fila'  => 1,
        ]);
    }

    function boton_facutar() {
        
        $this->browser->pause(500);

        $btn = "#btn_facturar";
        $this->browser->waitFor($btn);
        $this->browser->click($btn);
    }

    
    
}
