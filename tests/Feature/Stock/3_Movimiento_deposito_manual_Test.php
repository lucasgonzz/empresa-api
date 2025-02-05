<?php

namespace Tests\Feature\Stock;

use App\Models\Article;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class DepositMovementTest extends TestCase
{

    public $stock_a_mover = 5;
    public $article;
    public $stock_inicial;
    public $stock_resultante;


    /**
     * @group stock
     * @test
    */
    public function movimiento_deposito_manual()
    {

        $from_address_id = 1;
        $to_address_id = 2;

        $user = User::find(500);

        $this->actingAs($user, 'web');

        $this->article = Article::where('name', 'Kit 1')
                            ->first();


        $this->set_cantidades();

        $request = [
            'model_id'          => $this->article->id,
            'amount'            => $this->stock_a_mover,
            'concepto_stock_movement_name'  => 'Movimiento manual entre depositos',
            'from_address_id'   => $from_address_id,
            'to_address_id'     => $to_address_id,
        ];

        $response = $this->post('api/stock-movement', $request);

        $response->assertStatus(201);

        $this->assertEquals($this->stock_resultante, $this->article->fresh()->stock);

        $this->assertDatabaseHas('stock_movements', [
            'article_id'        => $this->article->id,
            'amount'            => $this->stock_a_mover,
            'from_address_id'   => $from_address_id,
            'to_address_id'     => $to_address_id,
            'amount'            => $this->stock_a_mover,
            'stock_resultante'  => $this->stock_resultante,
        ]);

        $this->console();
    }

    function console() {
        
        echo $this->article->name.', se movio '.$this->stock_a_mover.PHP_EOL;

        $this->article->load('addresses');

        foreach ($this->article->addresses as $address) {
            
            echo $address->street.': '.$address->pivot->amount.PHP_EOL;
        }
    }

    function set_cantidades() {

        $this->stock_inicial = $this->article->stock;

        if (is_null($this->stock_inicial)) {
            $this->stock_inicial = 0;
        }

        $this->stock_resultante = $this->article->stock;
    }
}
