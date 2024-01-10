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
        $user = User::where('company_name', 'Autopartes Boxes')
                    ->first();
        $buyers = Buyer::where('user_id', $user->id)
                            ->get();

        $articles = Article::where('name', 'Pintura para cama')
                            ->get();

        foreach ($buyers as $buyer) {
            $cart = Cart::create([
                'buyer_id' => $buyer->id,
                'user_id'   => $user->id,
            ]);
            foreach ($articles as $article) {
                $cart->articles()->attach($article->id, [
                    'amount'    => 5,
                    'price'     => $article->final_price,
                ]);
            }
        }
    }
}
