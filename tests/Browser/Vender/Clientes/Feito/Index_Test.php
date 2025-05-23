<?php

namespace Tests\Browser\Vender\Clientes\Feito;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\Browser\Helpers\AuthHelper;
use Tests\Browser\Vender\Clientes\Feito\Clases\crear_venta;
use Tests\Browser\Vender\Clientes\Feito\Clases\eliminar_venta;
use Tests\DuskTestCase;

class Index_Test extends DuskTestCase
{
    /**
     * A Dusk test example.
     *
     * @return void
     */
    public function feito_test_vender()
    {
        $this->browse(function (Browser $browser) {
            $browser = AuthHelper::login($browser);
            
            $browser->visit('/vender/remito');
            $browser->pause(5000);


            dump('**************************');
            dump('TEST_VENDER_Feito');
            dump('**************************');

            /*
                Creo venta con:
                    * Articulos con variantes
                    * Multiples metodos de pago (algunos con descuento) (algunos se facturan y otros no)
                    * Cliente creado en el momento. Buscado por CUIT y condicion de IVA Responsable Inscripto
            */
            new crear_venta($browser);

            /*
                * Elimino la venta creada y chequeo el stock de las variantes
            */
            new eliminar_venta($browser);


            dump('**************************');
            dump('TERMINA TEST_VENDER_Feito');
            dump('**************************');
        });
    }
}
