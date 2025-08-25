<?php

namespace Database\Seeders;

use App\Http\Controllers\Helpers\SaleHelper;
use App\Http\Controllers\Helpers\Seeders\SaleSeederHelper;
use App\Models\PriceType;
use App\Models\Sale;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class SaleDemoSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $num = 1;

        $cantidad_de_ventas_por_dia = 5;

        $price_types = PriceType::where('user_id', env('USER_ID'))
                                    ->get();

        for ($dias_atras=3; $dias_atras >= 0 ; $dias_atras--) { 

            $client_id = null;
            $employee_id = null;
            $address_id = 1;

            for ($venta=0; $venta < $cantidad_de_ventas_por_dia; $venta++) {

                $sale = [
                    'num'               => $num,
                    'employee_id'       => null,
                    'user_id'           => env('USER_ID'),
                    'terminada'         => 1,
                    'confirmed'         => 1,
                    'save_current_acount'=> 1,
                    'price_type_id'     => count($price_types) >= 1 ? rand(1, count($price_types)) : null,
                ];

                $num++;

                $sale['address_id'] = $address_id;  
                $sale['client_id'] = $client_id;  
                $sale['employee_id'] = $employee_id;  

                if ($venta == 2) {
                    $client_id = 1;
                    $employee_id = 1;
                } else if ($venta > 2) {
                    $client_id++;
                    $employee_id++;
                    $address_id++;
                }

                $sale['created_at'] = Carbon::now()->subDays($dias_atras);
                
                $sale['total'] = rand(10000, 100000);

                $created_sale = Sale::create($sale);

                $this->attach_articles($created_sale);
            }
        }
        

    }

    function attach_articles($created_sale) {
        
        $cantidad_articulos = rand(3,6);

        $total_articulo = $created_sale->total / $cantidad_articulos;

        $sale = [
            'articles' => []
        ];

        for ($article_id=1; $article_id <= $cantidad_articulos ; $article_id++) {
            
            $amount = rand(1,3); 
            
            $precio_articulo = $total_articulo / $amount; 
            
            $sale['articles'][] = [
                'id'            => $article_id,
                'price_vender'  => $precio_articulo,
                'cost'          => $precio_articulo / 2,
                'amount'        => $amount,
            ];
        }

        if (!$created_sale->client_id) {
            $sale['client_id'] = null;
            $sale['payment_methods'] = [
                [
                    'id'        => rand(2,4),
                    'amount'    => $created_sale->total / 2,
                ],
                [
                    'id'        => 5,
                    'amount'    => $created_sale->total / 2,
                ],
            ];
        } else {
            $sale['client_id'] = $created_sale->client_id;
            $sale['payment_methods'] = [];
        }
 
        SaleHelper::attachProperies($created_sale, SaleSeederHelper::setRequest($sale));

    }
}
