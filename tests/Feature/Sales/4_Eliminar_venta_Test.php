<?php

namespace Tests\Feature\Sales;

use App\Models\Article;
use App\Models\Client;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class Eliminar_venta_Test extends TestCase
{

    public $client_name = 'Lucas Gonzalez';
    public $sale;

    /**
     * @group sales
     * @test
    */
    public function eliminar_venta()
    {

        $user = User::find(500);

        $this->actingAs($user, 'web');

        $data = $this->get_sale();

        $response = $this->delete('api/sale/'.$data['id']);

        $response->assertStatus(200);

        $this->console();
    }

    function console() {
        echo 'Se elimino la venta '.PHP_EOL;
        
        foreach ($this->sale->articles as $article) {
            
            echo $article->name.' quedo en '.$article->stock.PHP_EOL;
        }
    }

    function get_sale() {

        $client = Client::where('name', $this->client_name)->first();
        
        $ultima_venta_de_cliente = Sale::where('client_id', $client->id)
                                        ->orderBy('id', 'DESC')
                                        ->first();

        $this->sale = $ultima_venta_de_cliente;

        if ($ultima_venta_de_cliente) {

            return [
                'id'                                => $ultima_venta_de_cliente->id,
            ];
        }

    }

}
