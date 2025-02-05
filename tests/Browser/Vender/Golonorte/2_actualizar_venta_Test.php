<?php

namespace Tests\Browser\Vender\Golonorte;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\Browser\Helpers\AuthHelper;
use Tests\Browser\Helpers\SaleHelper;
use Tests\Browser\Helpers\StockHelper;
use Tests\DuskTestCase;

class b_actualizar_venta_Test extends DuskTestCase
{
    /**
     * A Dusk test example.
     *
     * @return void
     * @group ventas_golonorte
     */
    public function test_2_actualizar_venta_golonorte()
    {
        $this->browse(function (Browser $browser) {

            $browser = AuthHelper::login($browser);
            
            $browser->visit('/ventas/todas/todos');
            
            $browser->pause(2000);

            $browser->click('td');
            
            $browser->pause(1000);

            $browser->click('@btn_actualizar_venta');

            $browser->pause(2000);

            $browser = SaleHelper::cambiar_amount($browser, 0, 5);

            $browser = SaleHelper::cambiar_amount($browser, 1, 3);
            
            $browser->pause(1000);


            /*
                * El precio de cada articulo queda igual
                    ya que la lista de precios no cambia

                Fanta $1.200 * 5 = 6.000
                Lima limon $1.200 * 3 = 3.600
                Total = 9.600

            */
            $browser = SaleHelper::check_total($browser, '$9.600'); 

            $browser = SaleHelper::btn_guardar($browser);

            $this->check_articles_stock();

        });
    }

    function check_articles_stock() {

        $data = StockHelper::get_address_stock_data('Lima limon', 'Santa Fe', 45);
        $this->assertDatabaseHas('address_article', $data);
        dump('----> Stock de Fanta OK');

        $data = StockHelper::get_address_stock_data('Fanta', 'Santa Fe', 47);
        $this->assertDatabaseHas('address_article', $data);
        dump('----> Stock de Fanta OK');

    }
}
