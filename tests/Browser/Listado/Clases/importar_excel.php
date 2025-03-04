<?php

namespace Tests\Browser\Listado\Clases;

use Tests\Browser\Helpers\FormHelper;
use Tests\Browser\Listado\Helpers\ImportHelper;
use Tests\Browser\Listado\Helpers\ListadoHelper;

class importar_excel {

    public $new_cost = 2000;

    function __construct($browser) {

        $this->browser = $browser;

        /*
            * Importo archivo con la misma estructura 
            * Modifico solo costos
        */
        // $this->excel_costos();


        /*
            * Importo archivo solo con las columnas:
                codigo de barras
                costo
                stock (solo deberia de afectar al stock con stock global)
        */
        $this->excel_solo_costos_stock();
    }

    function excel_solo_costos_stock() {

        $helper = new ImportHelper($this->browser);

        $helper->abrir_modal_importacion();

        $helper->marcar_props([
            [
                'key'       => 'Codigo_de_barras',
                'position'  => 2,
            ],
            [
                'key'       => 'Stock_actual',
                'position'  => 3,
            ],
            [
                'key'       => 'Costo',
                'position'  => 4,
            ],
        ]);

        $helper->subir_archivo("solo_costos_y_stock");

        $helper->marcar_crear_y_a_actualizar_radio();

        $helper->enviar_archivo(true);

        $helper->esperar_notificacion();

        $this->actualizar_listado();

        $this->check_article_cost_y_stock();
    }

    function check_article_cost_y_stock() {

        FormHelper::check_prop_value($this->browser, [
            'model_name'    => 'article',
            'fila'          => 4,
            'key'           => 'final_price',
            'value'         => '$2.420',
        ]);
        FormHelper::check_prop_value($this->browser, [
            'model_name'    => 'article',
            'fila'          => 4,
            'key'           => 'stock',
            'value'         => '200',
            // 'is_input'      => true,
        ]);

        FormHelper::check_prop_value($this->browser, [
            'model_name'    => 'article',
            'fila'          => 3,
            'key'           => 'stock',
            'value'         => '100',
            // 'is_input'      => true,
        ]);
    }

    function excel_costos() {

        $helper = new ImportHelper($this->browser);

        $helper->abrir_modal_importacion();

        $helper->subir_archivo("costos");

        $helper->marcar_crear_y_a_actualizar_radio();

        $helper->enviar_archivo();

        $helper->esperar_notificacion();

        $this->actualizar_listado();

        $this->check_article_cost();
    }

    function check_article_cost() {

        // FormHelper::check_prop_value($this->browser, [
        //     'model_name'    => 'article',
        //     'fila'          => 4,
        //     'key'           => 'cost',
        //     'value'         => $this->new_cost,
        // ]);

        FormHelper::check_prop_value($this->browser, [
            'model_name'    => 'article',
            'fila'          => 4,
            'key'           => 'final_price',
            'value'         => '$4.840',
        ]);
    }

    function actualizar_listado() {
        ListadoHelper::actualizar_listado($this->browser);
    }
    
}
