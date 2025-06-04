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
                            ->take(10)
                            ->get();

        foreach ($buyers as $buyer) {
            $cart = Cart::create([
                'buyer_id' => $buyer->id,
                'user_id'   => env('USER_ID'),
            ]);

            $total = 0;
            
            foreach ($articles as $article) {
                $amount = rand(1,6);
                $cart->articles()->attach($article->id, [
                    'amount'    => $amount,
                    'price'     => $article->final_price,
                ]);
                $total += $article->final_price * $amount;
            }

            $cart->total = $total;
            $cart->save();
        }
    }
}
