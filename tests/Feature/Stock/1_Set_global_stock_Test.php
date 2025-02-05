<?php

namespace Tests\Feature\Stock;

use App\Models\Article;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class GlobalTest extends TestCase
{

    public $stock_a_agregar = 10;

    /**
     * @group stock
     * @test
    */
    public function setear_stock_global()
    {

        $user = User::find(500);

        $this->actingAs($user, 'web');

        $article = Article::where('name', 'Stock global')
                            ->first();

        $stock_inicial = $article->stock;

        if (is_null($stock_inicial)) {
            $stock_inicial = 0;
        }

        $stock_resultante = $stock_inicial + $this->stock_a_agregar;

        $request = [
            'model_id'          => $article->id,
            'amount'            => $this->stock_a_agregar,
            'provider_id'       => 1,
        ];

        $response = $this->post('api/stock-movement', $request);

        $response->assertStatus(201);

        $this->assertEquals($stock_resultante, $article->fresh()->stock);

        $this->assertDatabaseHas('stock_movements', [
            'article_id'        => $article->id,
            'amount'            => $this->stock_a_agregar,
            'stock_resultante'  => $stock_resultante,
        ]);

        echo 'Se seteo '.$article->name.' con: '.$stock_resultante;
    }
}
