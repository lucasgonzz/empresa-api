<?php

namespace Database\Seeders;

use App\Models\Article;
use App\Models\Buyer;
use App\Models\Cart;
use App\Models\User;
use Illuminate\Database\Seeder;

class CartSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $buyers = Buyer::where('user_id', env('USER_ID'))
                            ->get();

        $articles = Article::where('user_id', env('USER_ID'))
                            ->get();

        foreach ($buyers as $buyer) {
            $cart = Cart::create([
                'buyer_id' => $buyer->id,
                'user_id'   => env('USER_ID'),
            ]);
            foreach ($articles as $article) {
                $cart->articles()->attach($article->id, [
                    'amount'    => rand(1,6),
                    'price'     => $article->final_price,
                ]);
            }
        }
    }
}
