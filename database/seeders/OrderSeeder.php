<?php

namespace Database\Seeders;

use App\Models\Article;
use App\Models\Buyer;
use App\Models\Order;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class OrderSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        /*
            Un buyer distinto por pedido, y los dos con comercio_city_client_id apuntando a un
            Client real (1 y 2 de ClientSeeder). Ese vinculo es la condicion que chequea
            CreateSaleOrderHelper::createSale() antes de pasarle client_id a la Sale: sin
            comercio_city_client la venta nace sin cliente y no impacta la cuenta corriente.
        */
        $buyer_lucas = Buyer::where('email', 'lucasgonzalez5500@gmail.com')->first();
        $buyer_marcos = Buyer::where('email', 'lucasgonzalez210200@gmail.com')->first();
        $models = [
            [
                'buyer_id'          => $buyer_lucas->id,
                'order_status_id'   => 1, // Sin confirmar (OrderStatusSeeder)
                'deliver'           => 0,
                'created_at'        => Carbon::now(),
            ],
            [
                'buyer_id'          => $buyer_marcos->id,
                'order_status_id'   => 1, // Sin confirmar (OrderStatusSeeder)
                'deliver'           => 0,
                'created_at'        => Carbon::now(),
            ],
        ];
        foreach ($models as $model) {
            $order = Order::create([
                'num'                   => 1,
                'buyer_id'              => $model['buyer_id'],
                'order_status_id'       => $model['order_status_id'],
                'deliver'               => $model['deliver'],
                'created_at'            => $model['created_at'],
                'user_id'               => config('app.USER_ID'),
            ]);

            $articles = Article::where('user_id', config('app.USER_ID'))
                                ->get();

            foreach ($articles as $article) {
                
                $order->articles()->attach($article->id, [
                    'amount'        => rand(1,20),
                    'price'         => $article->final_price,
                    'notes'         => 'Nota de ejemplo'
                ]); 
             }     
        }
    }
}
