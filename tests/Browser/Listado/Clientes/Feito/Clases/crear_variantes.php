<?php

namespace Tests\Browser\Listado\Clientes\Feito\Clases;

use App\Models\Address;
use Tests\Browser\Helpers\TableHelper;

class crear_variantes {

    public function __construct($browser) {
        
        $this->browser = $browser;

        $this->esperar_que_cargue_la_tabla();

        $this->abrir_variantes_y_guardar_propiedades();

        $this->indicar_stock_en_depositos();

        $this->check_depositos();

    }

    function esperar_que_cargue_la_tabla() {
        $this->browser->waitFor('#table-article');
        $this->browser->pause(1000);
    }

    function check_depositos() {

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

    function indicar_stock_en_depositos() {

        $this->abrir_modal_variantes();

        $this->browser->pause(500);

        $this->indicar_stock();

        $this->guardar_stock();

        $this->cerrar_modal_variantes();
    }

    function guardar_stock() {
        $btn = "#btn_actualizar_stock_variants";
        $this->browser->click($btn);
        $this->browser->pause(2000);
    }

    function indicar_stock() {
        $stocks = [
            [
                'variant'   => 'Azul_XL',
                'addresses' => [
                    'Buenos Aires'  => 10,
                    'Santa Fe'      => 10,
                ],
            ],
            [
                'variant'   => 'Amarillo_XL',
                'addresses' => [
                    'Buenos Aires'      => 40,
                    'Tucuman'           => 15,
                    'Mar del Plata'     => 15,
                ],
            ],
        ];

        foreach ($stocks as $stock) {
            
            foreach ($stock['addresses'] as $street => $amount) {
                
                $address_model = Address::where('street', $street)->first();
                
                $input_id = $stock['variant'].'-'.$address_model->id;
                $input = "#table-article_variant #$input_id";

                $this->browser->waitFor($input);
                $this->browser->type($input, $amount);
                $this->browser->pause(500);

            }

        }
    }

    /*
        * Desde el seeder creo las propiedades del articulo:
            Colores
            Talles#btn-variantes
        * Desde el Front, guardo los cambios en una de las propiedades 
        para que se creen dinamicamente y se guarden las article_variants 
    */
    function abrir_variantes_y_guardar_propiedades() {

        $this->abrir_modal_variantes();

        // Abro la primer propiedad y le doy al boton guardar
        $this->click_propiedades();

        $this->browser->pause(2000);

        $this->cerrar_modal_variantes();

    }

    function cerrar_modal_variantes() {
        $btn_close = "#article-variants .close";
        $this->browser->waitFor($btn_close);
        $this->browser->click($btn_close);
        $this->browser->pause(500);
    }

    function click_propiedades() {

        TableHelper::click_fila($this->browser, [
            'model_name'    => 'article_property',
            'fila'          => 1, 
        ]);

        $this->browser->pause(500);

        $this->browser->click('@btn_guardar_article_property');
    }

    function abrir_modal_variantes() {

        $fila_articulo = TableHelper::get_fila_a_chequear($this->browser, '#table-article', 'Nombre', 'Mate Torpedo');
        $fila_articulo++;

        $btn_variantes = "#table-article tr:nth-child($fila_articulo) #btn-variantes";
        $this->browser->pause(1000);
        $this->browser->waitFor($btn_variantes);
        $this->browser->scrollIntoView($btn_variantes);
        $this->browser->pause(500);
        $this->browser->click($btn_variantes);
        $this->browser->pause(500);
    }

}
