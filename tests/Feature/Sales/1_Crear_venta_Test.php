<?php

namespace Tests\Feature\Sales;

use App\Models\Article;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class Crear_venta_Test extends TestCase
{

    public $articles = [
        [
            'name'      => 'Kit 1',
            'amount'    => 7,
            'price'     => 100,
        ],
        [
            'name'      => 'Stock Global',
            'amount'    => 3,
            'price'     => 100,
        ],
    ];

    public $address_id = 2;
    public $client_name = 'Lucas Gonzalez';
    public $current_acount_payment_method_id = 1;



    /**
     * @group sales
     * @test
    */
    public function crear_venta()
    {


        $user = User::find(500);

        $this->actingAs($user, 'web');

        $data = $this->get_sale();

        $data = $this->add_items($data);

        $response = $this->post('api/sale', $data);

        $response->assertStatus(201);

        $this->console();
    }

    function console() {

        echo PHP_EOL;
        echo PHP_EOL;

        echo '--------> Se creo venta al cliente '.$this->client_name.PHP_EOL;

        foreach ($this->articles as $article) {
            
            $article_model = Article::where('name', $article['name'])->first();

            echo $article_model->name.' quedo en '.$article_model->stock.PHP_EOL;
        }

    }

    function add_items($sale) {

        $items = [];

        foreach ($this->articles as $article) {
            
            $article_model = Article::where('name', $article['name'])->first();

            $items[] = [
                'is_article'    => true,
                'id'            => $article_model->id,    
                'price_vender'  => $article['price'],    
                'amount'        => $article['amount'],    
            ];

        }

        $sale['items'] = $items;

        return $sale;
    }

    function get_sale() {

        $client = Client::where('name', $this->client_name)->first();
        
        $total = $this->total();

        return [
            'client_id'                         => $client->id,
            'address_id'                        => $this->address_id,
            'save_current_acount'               => 1,
            'omitir_en_cuenta_corriente'        => 0,
            'to_check'                          => 0,
            'current_acount_payment_method_id'  => $this->current_acount_payment_method_id,
            // 'price_type_id'                     => $request->price_type_id,
            'discounts_in_services'             => 1,
            'surchages_in_services'             => 1,
            'employee_id'                       => null,
            'sub_total'                         => $total,
            'total'                             => $total,
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

    function total() {

        $total = 0;

        foreach ($this->articles as $article) {
            
            $total_article = $article['price'] * $article['amount'];

            $total += $total_article;
        }

        return $total;
    }
}
