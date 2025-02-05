<?php

namespace Tests\Browser\Listado;

use App\Models\Address;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\Browser\Helpers\AuthHelper;
use Tests\DuskTestCase;

class crear_articulo_Test extends DuskTestCase
{

    public $articles = [
        [
            'bar_code'      => '1234',
            'name'          => 'Stock global',
            'cost'          => 1000,
            'stock_global'  => 1000,
            'provider_name' => 'Rosa',
        ],
        [
            'bar_code'      => '12345',
            'name'          => 'Stock depositos',
            'cost'          => 1000,
            'addresses'     => [
                [
                    'street'    => 'Mar del Plata',
                    'amount'    => 100,
                ],  
                [
                    'street'    => 'Buenos Aires',
                    'amount'    => 100,
                ],  
            ],  
        ],
    ];

    /**
     * @return void
     * @group listado
     */
    public function test_a_crear_articulos()
    {
        $this->browse(function (Browser $browser) {

            $browser = AuthHelper::login($browser);
            
            $browser->visit('/listado-de-articulos');
            $browser->pause(4000);

            foreach ($this->articles as $article) {
                
                $this->crear_articulo($browser, $article);
            }

            $browser->pause(1000);

            foreach ($this->articles as $article) {
                
                $this->asignar_stock($browser, $article);
            }

            $browser->pause(3000);
        });
    }


    function asignar_stock($browser, $article) {

        if (isset($article['stock_global'])) {

            $this->stock_global($browser, $article);
        
        } else if (isset($article['addresses'])) {

            $this->stock_depositos($browser, $article);

        }   

    }


    function stock_depositos($browser, $article) {


        $browser->script("
            let btn = document.querySelector('[dusk=\"btn_editar_depositos\"]');
            let event = new MouseEvent('click', { bubbles: false });
            btn.dispatchEvent(event);
        ");

        $browser->pause(1000);

        $browser->script("document.querySelector('.cont-table').scrollLeft += 800;");

        foreach ($article['addresses'] as $address) {
            
            $address_model = Address::where('street', $address['street'])->first();

            $input_address = '@'.$article['name'].'-'.$address_model->id;
            $browser->type($input_address, $address['amount']);
            $browser->pause(1000);

            dump('----> Se asigno stock de '.$address['amount'].' en '.$address['street'].' a '.$article['name']);
        }

        $browser->click('@btn_guardar_depositos');
    
    }



    function stock_global($browser, $article) {

        $browser->click('@btn_asignar_stock');
        $browser->pause(1000);

        $browser->type('#stock-movement-amount', $article['stock_global']);
        $browser->pause(1000);

        $browser->click('#stock-movement-search-povider');
        $browser->pause(1000);

        $browser->type('#stock-movement-search-povider-search-modal-input', $article['provider_name']);
        $browser->keys('#stock-movement-search-povider-search-modal-input', ['{CONTROL}']);
        $browser->pause(1000);
        $browser->keys('#stock-movement-search-povider-search-modal-input', ['{ENTER}']);



        $browser->type('@stock-movement-observations', 'Observaciones al crear');
        $browser->pause(1000);



        $browser->click('@btn_guardar_stock_movement');
        $browser->pause(2000);

        dump('----> Se asigno stock de '.$article['stock_global'].' a '.$article['name']);
    }

    function crear_articulo($browser, $article) {

        $browser->click('@btn_create_article');
        $browser->pause(1000);

        $browser->type('#article-bar_code', $article['bar_code']);
        $browser->keys('#article-bar_code', ['{ENTER}']);
        $browser->pause(1000);


        $browser->type('@article_name', $article['name']);
        $browser->pause(1000);


        $browser->type('#article-cost', $article['cost']);
        $browser->pause(1000);

        $browser->click('@btn_guardar_article');
        $browser->pause(1000);

        dump('----> Se creo articulo '.$article['name']);

    }
}
