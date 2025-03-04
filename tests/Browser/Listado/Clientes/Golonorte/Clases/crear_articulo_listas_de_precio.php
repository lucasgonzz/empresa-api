<?php

namespace Tests\Browser\Listado\Clientes\Golonorte\Clases;

use App\Http\Controllers\CommonLaravel\Helpers\Numbers;
use App\Models\Category;
use Tests\Browser\Helpers\FormHelper;
use Tests\Browser\Listado\Helpers\CrearArticuloHelper;

class crear_articulo_listas_de_precio {

    public $category_name = 'Almacen';
    public $cost = 1000;
    
    public function __construct($browser) {
        
        $this->browser = $browser;

        $this->crear_articulo();

        $this->browser->pause(1000);

        $this->check_listas_de_precio();

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

    function crear_articulo() {

        $articulo_para_crear = [
            'props' => [
                [
                    'key'   => 'bar_code',
                    'value' => '123456',
                ],
                [
                    'key'   => 'name',
                    'value' => 'Golonorte',
                ],
                [
                    'key'   => 'cost',
                    'value' => $this->cost,
                ],
                [
                    'key'   => 'provider_id',
                    'value' => 'Rosa',
                    'type'  => 'search',
                ],
                [
                    'key'   => 'category_id',
                    'value' => $this->category_name,
                    'type'  => 'search',
                ],
            ],
        ];

        CrearArticuloHelper::crear_articulo($this->browser, $articulo_para_crear);
    }
}
