<?php

namespace Tests\Browser\Vender\Golonorte;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\Browser\Helpers\AuthHelper;
use Tests\Browser\Helpers\SaleHelper;
use Tests\DuskTestCase;

class crear_venta_Test extends DuskTestCase
{
    /**
     * A Dusk test example.
     *
     * @return void
     */
    public function test_golonorte_crear_venta()
    {
        $this->browse(function (Browser $browser) {

            $browser = AuthHelper::login($browser);
            
            $browser->visit('/vender/remito');
            $browser->pause(2000);
            


            /*
                * Agrego YERBA
                * precio minorista = $1.150
                * total = $2.300
            */
            $browser = SaleHelper::add_article($browser, [
                'bar_code'              => '001',
                'amount'                => 2,
                'total_a_chequear'      => '$2.300'
            ]);


            
            /*
                * Agrego MATE TORPEDO
                * precio minorista = $1.150
                * total = $1.150
            */

            $browser = SaleHelper::add_article($browser, [
                'bar_code'              => '002',
                'amount'                => '',
                'total_a_chequear'      => '$3.450'
            ]);



            $browser = SaleHelper::set_address($browser, 'Tucuman');


            $browser->pause(2000)
                    ->press('@btn_vender')
                    ->pause(10000);

        });
    }
}
