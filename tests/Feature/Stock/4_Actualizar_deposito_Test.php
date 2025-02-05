<?php

namespace Tests\Feature\Stock;

use App\Models\Address;
use App\Models\Article;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class ActualizacionDepositoTest extends TestCase
{

    public $stock_a_agregar = 20;
    public $article;
    public $stock_inicial;
    public $stock_resultante;
    public $to_address_name = 'Tucuman';
    public $to_address_id = null;


    /**
     * @test
    */
    public function agregar_a_deposito()
    {

        $user = User::find(500);

        $this->actingAs($user, 'web');

        $this->article = Article::where('name', 'Kit 1')
                            ->first();


        $this->set_cantidades();

        $this->set_to_address_id();

        $request = [
            'model_id'          => $this->article->id,
            'amount'            => $this->stock_a_agregar,
            'to_address_id'     => $this->to_address_id,
            'concepto_stock_movement_name'  => 'Actualizacion de deposito',
        ];

        $response = $this->post('api/stock-movement', $request);

        $response->assertStatus(201);

        $this->assertEquals($this->stock_resultante, $this->article->fresh()->stock);

        $this->assertDatabaseHas('stock_movements', [
            'article_id'        => $this->article->id,
            'amount'            => $this->stock_a_agregar,
            'to_address_id'     => $this->to_address_id,
            'stock_resultante'  => $this->stock_resultante,
        ]);
    }

    function set_to_address_id() {

        $address = Address::where('street', $this->to_address_name)
                            ->first();

        $this->to_address_id = $address->id;
    }


    function set_cantidades() {

        $this->stock_inicial = $this->article->stock;

        if (is_null($this->stock_inicial)) {
            $this->stock_inicial = 0;
        }

        $this->stock_resultante = $this->stock_inicial + $this->stock_a_agregar;
    }
}
