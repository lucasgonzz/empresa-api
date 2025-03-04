<?php

namespace Tests\Browser\Listado\Clientes\Golonorte\Clases;

use App\Http\Controllers\CommonLaravel\Helpers\Numbers;
use App\Models\Category;
use Tests\Browser\Helpers\FormHelper;


class actualizar_costo_de_articulo {

    public $category_name = 'Almacen';
    public $cost = 2000;
    
    public function __construct($browser) {
        
        $this->browser = $browser;

        $this->actualizar_articulo();

        $this->browser->pause(1000);
        
        $this->check_listas_de_precio();

        $this->browser->pause(1000);

    }

    function actualizar_articulo() {

        FormHelper::update_model($this->browser, [
            'model_name'    => 'article',
            'fila'          => 1,
            'props'         => [
                [
                    'key'   => 'cost',
                    'value' => $this->cost,
                ]
            ],
        ]);
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
            ]);
        }
    }

}
