<?php

namespace Tests\Browser\Listado\Clientes\Golonorte\Clases;

use App\Http\Controllers\CommonLaravel\Helpers\Numbers;
use App\Models\Category;
use App\Models\PriceType;
use Tests\Browser\Helpers\FiltroHelper;
use Tests\Browser\Helpers\FormHelper;
use Tests\Browser\Helpers\TableHelper;


class actualizar_listas_de_precio_de_categoria {

    public $category_name = 'Almacen';
    public $cost = 2000;
    
    public function __construct($browser) {
        
        $this->browser = $browser;

        $this->actualizar_categoria();

        $this->cerrar_modal_article();

        $this->browser->pause(1000);

        $this->filtrar_article();
        
        $this->check_listas_de_precio();

    }

    function filtrar_article() {

        FiltroHelper::filtrar($this->browser, [
            'prop_key'      => 'num',
            'mayor_que'     => 4,
        ]);
    }

    function cerrar_modal_article() {
        $btn = "#article .close";

        $this->browser->waitFor($btn);
        $this->browser->click($btn);

        $this->browser->pause(500);
    }

    function check_listas_de_precio() {
        $category = Category::where('name', $this->category_name)->first();

        foreach ($category->price_types as $price_type) {

            $price = $this->cost + ($this->cost * $price_type->pivot->percentage / 100);

            $price = Numbers::price($price);
            
            FormHelper::check_prop_value($this->browser, [
                'model_name'    => 'article',
                'fila'          => 1,
                'key'           => 'price_type_'.$price_type->id,
                'value'         => $price,
            ], true);
        }
    }

    function actualizar_categoria() {

        $this->browser->pause(1000);
        // $this->browser->pause(8000);

        TableHelper::click_fila($this->browser, [
            'model_name'    => 'article',
            'fila'          => 1,
        ]);

        $this->browser->pause(500);

        $this->click_btn_edit_categoria();

        $this->actualizar_porcentajes_listas_de_precio();

        $this->guardar_categoria();

        $this->browser->pause(500);

        $this->cerrar_search_categoria();

        $this->browser->pause(500);
    }

    function cerrar_search_categoria() {
        $btn = "#article-category_id-search-modal .close";

        $this->browser->waitFor($btn);
        $this->browser->click($btn);
    }

    function guardar_categoria() {
        $this->browser->click("@btn_guardar_category");
    }

    function actualizar_porcentajes_listas_de_precio() {

        $price_types = PriceType::all();

        $percentage = 10;

        foreach ($price_types as $price_type) {
            FormHelper::update_model($this->browser, [
                'model_name'    => 'price_type',
                'props'         => [
                    [
                        'key'   => 'percentage-'.$price_type->id,
                        'value' => $percentage,
                    ]
                ],
            ], false, false);

            $percentage += 10;
        }
    }

    function click_btn_edit_categoria() {
        $btn = "#btn_edit_selected_category";
        $this->browser->waitFor($btn, 20);
        $this->browser->scrollIntoView($btn);
        $this->browser->pause(500);
        $this->browser->click($btn);
    }


}
