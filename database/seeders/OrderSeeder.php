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
        $user = User::where('company_name', 'Autopartes Boxes')->first();
        $buyer = Buyer::where('user_id', $user->id)->first();
        $models = [
            [
                'buyer_id'          => $buyer->id,
                'order_status_id'   => 1,
                'deliver'           => 0,
                // 'created_at'        => Carbon::now(),
                'created_at'        => Carbon::now()->subDays(2),
            ],
            [
                'buyer_id'          => $buyer->id,
                'order_status_id'   => 1,
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
                'user_id'               => $user->id,
            ]);

            $articles = [
                [
                    'name'          => 'Articulo 1',
                    'amount'        => 10,
                    // 'address_id'    => 1,
                ],
                [
                    'name'          => 'Articulo 2',
                    'amount'        => 1,
                ],
            ];

            foreach ($articles as $article) {
                $_article = Article::where('name', $article['name'])
                                    ->first();

                $order->articles()->attach($_article->id, [
                    'amount'        => $article['amount'],
                    'address_id'    => isset($article['address_id']) ? $article['address_id'] : null,
                    'price'         => $_article->final_price,
                    'notes'         => 'Esta es una nota bastante larga como para ocupar mucho lugar viste'
                ]); 
             }     
        }
    }
}
