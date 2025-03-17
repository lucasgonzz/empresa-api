<?php

namespace Tests\Browser\Listado\Clientes\NOMBRE_CLIENTE;

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
    public function test_listado_NOMBRE_CLIENTE()
    {
        $this->browse(function (Browser $browser) {
            $browser = AuthHelper::login($browser);
            
            $browser->visit('/listado-de-articulos');
            $browser->pause(4000);


            dump('**************************');
            dump('TEST_LISTADO_NOMBRE_CLIENTE');
            dump('**************************');

            dump('**************************');
            dump('TERMINA TEST_LISTADO_NOMBRE_CLIENTE');
            dump('**************************');
        });
    }
}
