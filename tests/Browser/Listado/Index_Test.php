<?php

namespace Tests\Browser\Listado;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\Browser\Helpers\AuthHelper;
use Tests\Browser\Helpers\FiltroHelper;
use Tests\Browser\Helpers\FormHelper;
use Tests\Browser\Helpers\ScrollHelper;
use Tests\Browser\Helpers\SeleccionHelper;
use Tests\Browser\Listado\Clases\act_articulo_repetido;
use Tests\Browser\Listado\Clases\actualizar_stock_en_depositos;
use Tests\Browser\Listado\Clases\asignar_stock_depositos;
use Tests\Browser\Listado\Clases\asignar_stock_global;
use Tests\Browser\Listado\Clases\crear_articulos;
use Tests\Browser\Listado\Clases\deposit_movement;
use Tests\Browser\Listado\Clases\divir_stock_en_depositos;
use Tests\Browser\Listado\Clases\filtrar;
use Tests\Browser\Listado\Clases\importar_excel;
use Tests\Browser\Listado\Clases\movimiento_manual_depositos;
use Tests\Browser\Listado\Helpers\ActualizarProviderHelper;
use Tests\Browser\Listado\Helpers\ActualizarRepetidoHelper;
use Tests\Browser\Listado\Helpers\DepositMovementHelper;
use Tests\Browser\Listado\Helpers\FiltrarArticleHelper;
use Tests\Browser\Listado\Helpers\MovimientoDepositosHelper;
use Tests\Browser\Listado\Helpers\StockHelper;
use Tests\Browser\Listado\Helpers\StockMovementHelper;
use Tests\DuskTestCase;

class Index_Test extends DuskTestCase
{
    /**
     * A Dusk test example.
     *
     * @return void
     */
    public function test_listado()
    {
        $this->browse(function (Browser $browser) {
            $browser = AuthHelper::login($browser);
            
            $browser->visit('/listado-de-articulos');
            $browser->pause(6000);

            dump('**************************');
            dump('TEST_LISTADO');
            dump('**************************');

            // new importar_excel($browser);
            // return;   


            // Creo articulo "Stock Global" y "Stock depositos"
            new crear_articulos($browser);

            // Asigno stock global a "Stock global"
            // Despues le sumo y le resto stock
            new asignar_stock_global($browser);


            // Divido el stock global en depositos
            new divir_stock_en_depositos($browser);


            // Actualizo el stock en los depositos
            new actualizar_stock_en_depositos($browser);


            // Asigno stock global a "Stock depositos"
            new asignar_stock_depositos($browser);

            // Actualizo el costo de "Stock depositos"
            new act_articulo_repetido($browser);

            // Muevo stock de "Stock global" desde "Mar del plata" hacia "Santa Fe"
            new movimiento_manual_depositos($browser);

            // Creo deposit_movement para ambos articulos
            new deposit_movement($browser);

            // Filtro, selecciono y actualizo articulo
            new filtrar($browser);


            dump('**************************');
            dump('TERMINA test_listado');
            dump('**************************');

        });
    }

}
