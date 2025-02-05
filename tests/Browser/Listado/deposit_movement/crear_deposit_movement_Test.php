<?php

namespace Tests\Browser\Listado\deposit_movement;

use App\Models\Address;
use App\Models\Article;
use App\Models\DepositMovementStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\Browser\Helpers\AuthHelper;
use Tests\DuskTestCase;

class crear_deposit_movement_Test extends DuskTestCase
{

    public $deposit_movements = [
        [
            'employee_name'     => 'Patricio',
            'deposito_origen'   => 'Tucuman',
            'deposito_destino'  => 'Mar del plata',
            'articles'          => [
                [
                    'name'      => 'Mate torpedo',
                    'amount'    => 10,
                ],
                [
                    'name'      => 'Fanta',
                    'amount'    => 25,
                ],
            ],
        ],
        [
            'employee_name'     => 'Patricio',
            'deposito_origen'   => 'Tucuman',
            'deposito_destino'  => 'Mar del plata',
            'articles'          => [
                [
                    'name'      => 'Mate torpedo',
                    'amount'    => 50,
                ],
                [
                    'name'      => 'Fanta',
                    'amount'    => 50,
                ],
            ],
            'estado'            => 'Recibido',
        ],
    ];

    /**
     * @return void
     */
    public function test_crear_deposit_movements()
    {
        $this->browse(function (Browser $browser) {
            $browser = AuthHelper::login($browser);
            
            $browser->visit('/listado-de-articulos');
            $browser->pause(4000);

            $browser->click('@btn_deposit_movements');
            $browser->pause(1000);
            


            foreach ($this->deposit_movements as $deposit_movement) {
                
                $this->crear_deposit_movement($browser, $deposit_movement);
            }


            // foreach ($this->deposit_movements as $deposit_movement) {
                
            //     $this->marcar_como_recibido($browser, $deposit_movement);
            // }

        });
    }

    // function marcar_como_recibido($browser, $deposit_movement) {

    //     if ($deposit_movement['marcar_como_recibido']) {

    //         $browser->click
    //     }
    // }

    function crear_deposit_movement($browser, $deposit_movement) {

        // Boton modal crear 
        $browser->click('@btn_create_deposit_movement');
        $browser->pause(1000);



        // Deposito origen
        $from_address = Address::where('street', $deposit_movement['deposito_origen'])->first();

        $browser->select('#deposit_movement-from_address_id', $from_address->id);
        $browser->pause(1000);


        // Deposito destino
        $to_address = Address::where('street', $deposit_movement['deposito_destino'])->first();

        $browser->select('#deposit_movement-to_address_id', $to_address->id);
        $browser->pause(1000);


        // Articulos
        foreach ($deposit_movement['articles'] as $article) {

            $this->agregar_articulo($browser, $article);
        }



        // Empelado
        $employee = User::where('name', $deposit_movement['employee_name'])->first();

        $browser->select('#deposit_movement-employee_id', $employee->id);
        $browser->pause(1000);


        // Estado
        if (isset($deposit_movement['estado'])) {

            $estado = DepositMovementStatus::where('name', $deposit_movement['estado'])->first();

            $browser->select('#deposit_movement-deposit_movement_status_id', $estado->id);
            $browser->pause(1000);
        }
        $employee = User::where('name', $deposit_movement['employee_name'])->first();

        $browser->select('#deposit_movement-employee_id', $employee->id);
        $browser->pause(1000);



        $browser->pause(1000);
        $browser->click('@btn_guardar_deposit_movement');
        $browser->pause(3000);

    }

    function agregar_articulo($browser, $article) {

        $browser->click('#deposit_movement-articles');
        $browser->pause(1000);

        $browser->type('#deposit_movement-articles-search-modal-input', $article['name']);
        $browser->keys('#deposit_movement-articles-search-modal-input', ['{CONTROL}']);
        $browser->pause(1000);
        
        $browser->waitFor('@table-results-article');
        $browser->pause(1000);
        
        $browser->keys('#deposit_movement-articles-search-modal-input', ['{ENTER}']);

        $browser->pause(1000);

        $article_model = Article::where('name', $article['name'])->first();

        $input_amount = '#article-amount-'.$article_model->id;

        $browser->waitFor($input_amount);
        $browser->type($input_amount, $article['amount']);

        $browser->pause(500);
    }
}
