<?php

namespace Database\Seeders;

use App\Models\Article;
use App\Models\Buyer;
use App\Models\Order;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

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

        /*
            Guarda agregada en la mision 63 (siembra-local-igual-a-demo), cuando este seeder pasó
            a correr también dentro de `DemoSetupHelper::run()`. Sin ella, un `BuyerSeeder` que
            algún día cambie esos dos mails deja `$buyer_lucas` en null y el `->id` de más abajo
            tira un fatal: en local eso es una corrida de seeders que se corta, pero en una demo
            es el setup de un lead YA AGENDADO que muere después del `migrate:fresh`, o sea una
            instancia con la base vacía. Se sale sin sembrar y se avisa, que es la diferencia
            entre "faltan dos pedidos de ejemplo" y "no hay sistema".
        */
        if (is_null($buyer_lucas) || is_null($buyer_marcos)) {
            Log::warning('OrderSeeder: no se encontraron los dos Buyer de BuyerSeeder, no se siembra ningun pedido.');
            return;
        }

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
                                ->take(10)
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
