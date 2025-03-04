<?php

namespace Tests\Browser\Vender\Golonorte;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\Browser\Helpers\AuthHelper;
use Tests\Browser\Helpers\SaleHelper;
use Tests\Browser\Helpers\Sale\ClientHelper;
use Tests\Browser\Helpers\StockHelper;
use Tests\DuskTestCase;

class a_crear_venta_articulos_agrupados_Test extends DuskTestCase
{

    public $client_name = 'Lucas Gonzalez';

    /**
     * A Dusk test example.
     *
     * @return void
     * @group ventas_golonorte
     */
    public function test_1_golonorte_crear_venta_articulos_agrupados()
    {
        $this->browse(function (Browser $browser) {

            $browser = AuthHelper::login($browser);
            
            $browser->visit('/vender/remito');
            $browser->pause(2000);
            


            /*
                * Agrego Fanta
                * precio minorista = $1.300
                * total = $2.600
            */
            $browser = SaleHelper::add_article($browser, [
                'bar_code'              => '003',
                'amount'                => 2,
                'total_a_chequear'      => '$2.600'
            ]);


            
            /*
                * Agrego Lima limon
                * precio minorista = $1.150
                * Cuando lo agregue, 
                    se cambia la lista de precio de ambos
                    y queda en "Mayorista"
                    La lista Mayorista para GASEOSAS tiene un 25%
                    Ambos quedan con el precio de $1.250 (1000 + 25)
            */

            $browser = SaleHelper::add_article($browser, [
                'bar_code'              => '004',
                'amount'                => 5,
                'total_a_chequear'      => '$8.750'
            ]);



            /*
                * Cambio la cantidad de lima limon a 3
                Cambia la lista de ambos a "Minorista"
                Precio de 1.300 para c/u
                Nuevo total:
                    Cocacola * 2 = 2.600
                    Lima limon * 3 = 3.900
                    Total final = 5.200
            */
            $browser = $this->cambiar_listas_de_precios_cambiando_la_cantidad(
                $browser,
                3,
                '$6.500'
            );



            /*
                * Cambio la cantidad de lima limon a 12
                Cambia la lista de ambos a "Mayorista"
                Precio de 1.200 para c/u
                Nuevo total:
                    Cocacola * 2 = 2.400
                    Lima limon * 12 = 14.400
                    Total final = 16.800
            */
            $browser = $this->cambiar_listas_de_precios_cambiando_la_cantidad(
                $browser,
                12,
                '$16.800'
            );


            $browser = SaleHelper::set_address($browser, 'Santa Fe');


            $browser = ClientHelper::select_client($browser, $this->client_name);


            $browser = SaleHelper::btn_guardar($browser);


            $data = StockHelper::get_address_stock_data('Fanta', 'Santa Fe', 48);
            $this->assertDatabaseHas('address_article', $data);
            dump('----> Stock de Fanta OK');


            $data = StockHelper::get_address_stock_data('Lima limon', 'Santa Fe', 38);
            $this->assertDatabaseHas('address_article', $data);
            dump('----> Stock de Lima limon OK');


            $this->assertDatabaseHas('clients', [
                'name'  => $this->client_name,
                'saldo' => 16800
            ]);
            dump('----> Saldo de '.$this->client_name.' Ok');


        });
    }


    function cambiar_listas_de_precios_cambiando_la_cantidad($browser, $amount, $total) {

        $browser = SaleHelper::cambiar_amount($browser, 0, $amount);
        $browser->pause(500);
        $browser = SaleHelper::check_total(
            $browser,
            $total,
        );
        $browser->pause(500);

        return $browser;
    }
}

