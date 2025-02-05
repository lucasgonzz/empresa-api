<?php

namespace Tests\Feature\Sales;

use App\Models\Article;
use App\Models\Client;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use Illuminate\Support\Facades\Log;

class Eliminar_articulo_de_venta_Test extends TestCase
{

    public $articles = [
        [
            'name'      => 'Kit 1',
        ],
    ];

    public $client_name = 'Lucas Gonzalez';
    public $sale = null;


    /**
     * @group sales
     * @test
    */
    public function eliminar_articulo_de_venta()
    {

        $user = User::find(500);

        $this->actingAs($user, 'web');

        $data = $this->get_sale();

        $data = $this->eliminar_items($data);

        $data = $this->set_sale_total($data);

        $response = $this->put('api/sale/'.$data['id'], $data);

        $response->assertStatus(200);

        $this->console();
    }

    function console() {
        echo 'Se elimino articulo de la venta '.PHP_EOL;
        
        foreach ($this->articles as $article) {
            
            $article_model = Article::where('name', $article['name'])->first();

            echo $article_model->name.' quedo en '.$article_model->stock.PHP_EOL;
        }
    }

    function eliminar_items($data) {

        $items = [];

        foreach ($this->sale->articles as $article) {
            
            $items[] = [
                'is_article'    => true,
                'id'            => $article->id,    
                'name'          => $article->name,    
                'price_vender'  => $article->pivot->price,    
                'amount'        => $article->pivot->amount,    
            ];

        }

        foreach ($this->articles as $article) {

            $key = array_search($article['name'], array_column($items, 'name'));
            
            Log::info('Se va a eliminar el indice de '.$key);
            unset($items[$key]);
        }

        Log::info('Items despues de eliminar el article:');
        Log::info($items);

        $data['items'] = $items;

        return $data;
    }

    function get_sale() {

        $client = Client::where('name', $this->client_name)->first();
        
        $this->sale = Sale::where('client_id', $client->id)
                                        ->orderBy('id', 'DESC')
                                        ->first();

        if ($this->sale) {

            return [
                'id'                                => $this->sale->id,
                'client_id'                         => $client->id,
                'address_id'                        => $this->sale->address_id,
                'save_current_acount'               => 1,
                'omitir_en_cuenta_corriente'        => 0,
                'to_check'                          => 0,
                'checked'                           => 0,
                'confirmed'                         => 0,
                // 'price_type_id'                     => $request->price_type_id,
                'discounts_in_services'             => 1,
                'surchages_in_services'             => 1,
                'employee_id'                       => null,
                'terminada'                         => 1,
                'seller_id'                         => null,
                'cantidad_cuotas'                   => null,
                'cuota_descuento'                   => 0,
                'cuota_recargo'                     => 0,
                'caja_id'                           => null,
                'afip_tipo_comprobante_id'          => null,
                'descuento'                         => null,
                
                'discounts'                         => [],
                'surchages'                         => [],
            ];
        }

    }

    function set_sale_total($data) {
        $total = $this->get_total($data);

        $data['sub_total'] = $total;
        $data['total'] = $total;

        return $data;
    }

    function get_total($data) {

        $total = 0;

        foreach ($data['items'] as $item) {
            
            $total_article = $item['price_vender'] * $item['amount'];

            $total += $total_article;
        }

        return $total;
    }
}
