<?php

namespace Tests\Browser\Listado\Clientes\Feito;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\Browser\Helpers\AuthHelper;
use Tests\Browser\Listado\Clientes\Feito\Clases\crear_variantes;
use Tests\Browser\Listado\Clientes\Feito\Clases\deposit_movement;
use Tests\DuskTestCase;

class Index_Test extends DuskTestCase
{
    /**
     * A Dusk test example.
     *
     * @return void
     */
    public function test_listado_feito()
    {
        $this->browse(function (Browser $browser) {
            $browser = AuthHelper::login($browser);
            
            $browser->visit('/listado-de-articulos');
            $browser->pause(6000);


            dump('**************************');
            dump('TEST_LISTADO_FEITO');
            dump('**************************');

            /*
                Creo variantes y asigno stock por deposito
            */
            new crear_variantes($browser);


            /*
                Creo deposit_movement y agrego mas de una vez el mismo articulo,
                con distintas variantes
            */
            // new deposit_movement($browser);


            dump('**************************');
            dump('TERMINA TEST_LISTADO_FEITO');
            dump('**************************');
        });
    }
}
