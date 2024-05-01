<?php

namespace App\Http\Controllers\Helpers\database;

use App\Http\Controllers\Helpers\DatabaseHelper;
use App\Models\Cart;
use App\Models\User;

class DatabaseCartHelper {

    static function copiar_carts($user, $bbdd_destino) {

        if (!is_null($user)) {

            $carts = Cart::where('user_id', $user->id)
                            ->orderBy('id', 'ASC')
                            ->with('articles')
                            ->get();

            DatabaseHelper::set_user_conecction($bbdd_destino);
            
            foreach ($carts as $cart) {

                $cart_ya_creado = Cart::find($cart->id);

                if (is_null($cart_ya_creado)) {
                    
                    $new_cart = [
                        'id'                        => $cart->id,
                        'deliver'                   => $cart->deliver,
                        'address_id'                => $cart->address_id,
                        'delivery_zone_id'          => $cart->delivery_zone_id,
                        'payment_method_id'         => $cart->payment_method_id,
                        'payment_card_info_id'      => $cart->payment_card_info_id,
                        'payment_id'                => $cart->payment_id,
                        'payment_status'            => $cart->payment_status,
                        'description'               => $cart->description,
                        'cupon_id'                  => $cart->cupon_id,
                        'order_id'                  => $cart->order_id,
                        'buyer_id'                  => $cart->buyer_id,
                        'user_id'                   => $cart->user_id,
                    ];

                    $created_cart = Cart::create($new_cart);
                    echo 'Se creo cart id: '.$created_cart->id.' </br>';

                    Self::attach_articles($created_cart, $cart);
                }
            }
        }
    }

    
    static function attach_articles($created_cart, $cart) {
        foreach ($cart->articles as $article) {
            $created_cart->articles()->attach($article->id, [
                'amount'                => $article->pivot->amount,
                'amount_insuficiente'   => $article->pivot->amount_insuficiente,
                'price'                 => $article->pivot->price,
                'variant_id'            => $article->pivot->variant_id,
                'notes'                 => $article->pivot->notes,
            ]);
        }
    }

}