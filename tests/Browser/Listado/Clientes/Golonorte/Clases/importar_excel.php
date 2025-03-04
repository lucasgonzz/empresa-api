<?php

namespace Tests\Browser\Listado\Clientes\Golonorte\Clases;

use App\Http\Controllers\CommonLaravel\Helpers\Numbers;
use App\Models\Category;
use Tests\Browser\Helpers\FiltroHelper;
use Tests\Browser\Helpers\FormHelper;
use Tests\Browser\Listado\Helpers\ImportHelper;
use Tests\Browser\Listado\Helpers\ListadoHelper;

class importar_excel {

    public $category_name = 'Almacen';
    public $cost_articulo_creado = 1000;
    public $cost_articulo_actualizado = 3000;

    public function __construct($browser) {
        
        $this->browser = $browser;

        $helper = new ImportHelper($browser);

        $helper->abrir_modal_importacion();

        $helper->subir_archivo("excel_golonorte");

        $helper->marcar_crear_y_a_actualizar_radio();

        $helper->enviar_archivo();

        $helper->esperar_notificacion();

        $this->actualizar_listado();

        $this->check_articulo_creado();

        $this->check_articulo_actualizado();
    }

    function actualizar_listado() {
        ListadoHelper::actualizar_listado($this->browser);
    }

    function check_articulo_actualizado() {
        
        dump('check articulo actualizado');

        $category = Category::where('name', $this->category_name)->first();

        foreach ($category->price_types as $price_type) {

            $price = $this->cost_articulo_actualizado + ($this->cost_articulo_actualizado * $price_type->pivot->percentage / 100);

            $price = Numbers::price($price);
            
            FormHelper::check_prop_value($this->browser, [
                'model_name'    => 'article',
                'fila'          => 2,
                'key'           => 'price_type_'.$price_type->id,
                'value'         => $price,
            ], true);
        }
    }

    function check_articulo_creado() {

        dump('check articulo creado');
        
        $category = Category::where('name', $this->category_name)->first();

        foreach ($category->price_types as $price_type) {

            $price = $this->cost_articulo_creado + ($this->cost_articulo_creado * $price_type->pivot->percentage / 100);

            $price = Numbers::price($price);
            
            FormHelper::check_prop_value($this->browser, [
                'model_name'    => 'article',
                'fila'          => 1,
                'key'           => 'price_type_'.$price_type->id,
                'value'         => $price,
            ], true);
        }
    }

}
