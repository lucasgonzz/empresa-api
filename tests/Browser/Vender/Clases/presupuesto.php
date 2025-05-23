<?php

namespace Tests\Browser\Vender\Clases;

use Tests\Browser\Helpers\TableHelper;
use Tests\Browser\Listado\Helpers\StockMovementHelper;
use Tests\Browser\Vender\Helpers\ClientHelper;
use Tests\Browser\Vender\Helpers\RemitoHelper;
use Tests\Browser\Vender\Helpers\VenderHelper;

class presupuesto {

    function __construct($browser) {

        $this->browser = $browser;

        $this->crear_presupuesto();

        $this->actualizar_presupuesto();

        $this->confirmar();

        $this->check_articles_stock();
    }

    function check_articles_stock() {

        $this->browser->visit('listado-de-articulos');
        $this->browser->pause(2000);

        StockMovementHelper::check_movement($this->browser, [
            'fila'              => 1,
            'fila_movimiento'   => 1,
            'cantidad'          => 5,
            'stock_resultante'  => 195,
            'concepto'          => 'Venta',
            'deposito_origen'   => 'Buenos Aires',
        ]);


        StockMovementHelper::check_movement($this->browser, [
            'fila'              => 2,
            'fila_movimiento'   => 1,
            'cantidad'          => 10,
            'stock_resultante'  => 90,
            'concepto'          => 'Venta',
            'deposito_origen'   => 'Buenos Aires',
        ]);
    }

    function confirmar() {
        $this->abrir_presupuesto();

        $this->marcar_confirmado();
    }

    function marcar_confirmado() {
        $select = "#budget-budget_status_id";

        $this->browser->scrollIntoView($select);
        $this->browser->pause(500);

        $this->browser->select($select, 2);

        $this->browser->pause(500);

        $this->browser->click("@btn_guardar_budget");
        
        $this->browser->pause(2000);

    }

    function actualizar_presupuesto() {
        $this->seleccionar_presupuesto();

        $this->actualizar_cantidad();
        $this->guardar_presupuesto();
    }

    function actualizar_cantidad() {
        RemitoHelper::cambiar_amount($this->browser, [
            'index'         => 1,
            'new_amount'    => 10,
        ]); 
    }

    function seleccionar_presupuesto() {

        $this->abrir_presupuesto();

        $this->browser->pause(500);
        $btn_actualizar = "#btn_actualizar_en_vender";
        $this->browser->waitFor($btn_actualizar);
        $this->browser->click($btn_actualizar);
    }

    function abrir_presupuesto() {

        $this->browser->visit('presupuestos');
        $this->browser->pause(1000);

        // La primera es el titulo del budget_status
        $fila = 2;
        TableHelper::click_fila($this->browser, [
            'model_name'    => 'budget',
            'fila'          => $fila,
        ]);
    }

    function crear_presupuesto() {

        $this->agregar_articulos();
        $this->set_address();
        $this->agregar_cliente();
        $this->marcar_guardar_como_presupuesto();
        $this->guardar_presupuesto();
    }

    function guardar_presupuesto() {
        VenderHelper::btn_guardar($this->browser);
    }

    function marcar_guardar_como_presupuesto() {
        ClientHelper::guardar_como_presupuesto($this->browser);
    }

    function agregar_cliente() {
        ClientHelper::select_client($this->browser, 'Lucas gonzalez');

        $this->browser->pause(500);
    }

    function set_address() {

        VenderHelper::set_address($this->browser, 'Buenos Aires');

        $this->browser->pause(500);
    }

    function agregar_articulos() {

        RemitoHelper::add_article_bar_code($this->browser,'001', 5);

        $this->browser->pause(1000);

        RemitoHelper::add_article_bar_code($this->browser,'002', 5);

        $this->browser->pause(1000);

        RemitoHelper::check_total($this->browser, '$25.500');
    }
    
}
