<?php

namespace Tests\Browser\Listado\Clientes\Golonorte;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\Browser\Helpers\AuthHelper;
use Tests\Browser\Listado\Clientes\Golonorte\Clases\actualizar_costo_de_articulo;
use Tests\Browser\Listado\Clientes\Golonorte\Clases\actualizar_listas_de_precio_de_categoria;
use Tests\Browser\Listado\Clientes\Golonorte\Clases\crear_articulo_listas_de_precio;
use Tests\Browser\Listado\Clientes\Golonorte\Clases\importar_excel;
use Tests\DuskTestCase;

class Index_Test extends DuskTestCase
{
    /**
     * A Dusk test example.
     *
     * @return void
     */
    public function test_listado_golonorte()
    {
        $this->browse(function (Browser $browser) {
            $browser = AuthHelper::login($browser);
            
            $browser->visit('/listado-de-articulos');
            $browser->pause(4000);


            dump('**************************');
            dump('TEST_LISTADO_GOLONORTE');
            dump('**************************');

            // Creo articulo y chequeo listas de precios en base a la categoria
            new crear_articulo_listas_de_precio($browser);

            // Le actualizo el costo y chequeo listas de precio
            new actualizar_costo_de_articulo($browser);

            /* 
                Actualizo los margenes de las listas de precio de la categoria y chequeo
                los nuevos precios del articulo
            */
            new actualizar_listas_de_precio_de_categoria($browser);


            new importar_excel($browser);

            dump('**************************');
            dump('TERMINA TEST_LISTADO_GOLONORTE');
            dump('**************************');
        });
    }
}
