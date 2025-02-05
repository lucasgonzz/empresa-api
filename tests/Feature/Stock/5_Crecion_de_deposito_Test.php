<?php

namespace Tests\Feature\Stock;

use App\Models\Address;
use App\Models\Article;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class Creacion_de_deposito_Test extends TestCase
{

    public $stock_a_agregar = 10;

    public $article_name = 'Stock global';
    public $article;

    public $addresses = [
        [
            'street'    => 'Tucuman',
            'amount'    => 5,
        ],
        [
            'street'    => 'Santa Fe',
            'amount'    => 10,
        ],
    ];

    
    public $stock_inicial;
    public $stock_resultante;
    public $to_address_id = 1;


    public function test_creacion_depositos()
    {

        $user = User::find(500);

        $this->actingAs($user, 'web');

        $this->article = Article::where('name', $this->article_name)
                            ->first();


        $this->set_cantidades();

        $segundos = 1;

        foreach ($this->addresses as $address) {
            
            $address_model = Address::where('street', $address['street'])->first();

            $request = [
                'model_id'          => $this->article->id,
                'amount'            => $address['amount'],
                'to_address_id'     => $address_model->id,
                'concepto_stock_movement_name'  => 'Creacion de deposito',
                'segundos_para_agregar' => $segundos,
            ];

            $response = $this->post('api/stock-movement', $request);

            $segundos += 5;
            
            $response->assertStatus(201);

            echo 'Se agrego '.$address_model->street;
            echo $this->article->name.' con stock de '.$this->article->fresh()->stock;

            $this->assertDatabaseHas('stock_movements', [
                'article_id'        => $this->article->id,
                'amount'            => $address['amount'],
                'to_address_id'     => $address_model->id,
                'stock_resultante'  => $this->article->fresh()->stock,
            ]);
        }


        $this->assertEquals($this->stock_resultante, $this->article->fresh()->stock);

    }

    function set_cantidades() {

        $this->stock_resultante = 0;

        if (count($this->article->addresses) >= 1) {

            $this->stock_resultante = $this->article->stock;
        }
        
        foreach ($this->addresses as $address) {
            
            $this->stock_resultante += $address['amount'];
        }
    }
}
