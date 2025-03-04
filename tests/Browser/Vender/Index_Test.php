<?php

namespace Tests\Browser\Vender;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\Browser\Helpers\AuthHelper;
use Tests\Browser\Vender\Clases\actualizar_metodos_de_pago;
use Tests\Browser\Vender\Clases\crear_venta;
use Tests\Browser\Vender\Clases\facturar_venta;
use Tests\Browser\Vender\Clases\venta_cliente;
use Tests\Browser\Vender\Clases\venta_cliente_omitir_cc;
use Tests\DuskTestCase;

class Index_Test extends DuskTestCase
{
    /**
     * A Dusk test example.
     *
     * @return void
     */
    public function test_vender()
    {
        $this->browse(function (Browser $browser) {
            $browser = AuthHelper::login($browser);
            
            $browser->visit('/vender/remito');
            $browser->pause(2000);


            dump('**************************');
            dump('TEST_VENDER');
            dump('**************************');

            new crear_venta($browser);

            new venta_cliente($browser);

            new venta_cliente_omitir_cc($browser);
            new actualizar_metodos_de_pago($browser);
            new facturar_venta($browser);


            dump('**************************');
            dump('TERMINA TEST_VENDER');
            dump('**************************');
        });
    }

}
