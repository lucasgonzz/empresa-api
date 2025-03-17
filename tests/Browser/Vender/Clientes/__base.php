<?php

namespace Tests\Browser\Vender\Clientes\NOMBRE_CLIENTE;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\Browser\Helpers\AuthHelper;
use Tests\DuskTestCase;

class Index_Test extends DuskTestCase
{
    /**
     * A Dusk test example.
     *
     * @return void
     */
    public function test_vender_NOMBRE_CLIENTE()
    {
        $this->browse(function (Browser $browser) {
            $browser = AuthHelper::login($browser);
            
            $browser->visit('/vender/remito');
            $browser->pause(4000);


            dump('**************************');
            dump('TEST_vender_NOMBRE_CLIENTE');
            dump('**************************');

            dump('**************************');
            dump('TERMINA TEST_vender_NOMBRE_CLIENTE');
            dump('**************************');
        });
    }
}
