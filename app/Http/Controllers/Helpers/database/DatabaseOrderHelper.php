<?php

namespace App\Http\Controllers\Helpers\database;

use App\Http\Controllers\Helpers\DatabaseHelper;
use App\Models\Order;
use App\Models\User;

class DatabaseOrderHelper {

    static function copiar_orders($user, $bbdd_destino) {

        if (!is_null($user)) {

            $orders = Order::where('user_id', $user->id)
                            ->orderBy('id', 'ASC')
                            ->with('articles')
                            ->get();

            DatabaseHelper::set_user_conecction($bbdd_destino);
            
            foreach ($orders as $order) {

                $order_ya_creada = Order::find($order->id);

                if (is_null($order_ya_creada)) {
                    
                    $new_order = [
                        'id'                        => $order->id,
                        'num'                       => $order->num,
                        'buyer_id'                  => $order->buyer_id,
                        'user_id'                   => $order->user_id,
                        'payment_id'                => $order->payment_id,
                        'payment_card_info_id'      => $order->payment_card_info_id,
                        'payment_method_id'         => $order->payment_method_id,
                        'delivery_zone_id'          => $order->delivery_zone_id,
                        'cupon_id'                  => $order->cupon_id,
                        'percentage_card'           => null,
                        'deliver'                   => $order->deliver,
                        'description'               => $order->description,
                        'order_status_id'           => $order->order_status_id,
                        'payment_method_discount'   => $order->payment_method_discount,
                        'payment_method_surchage'   => $order->payment_method_surchage,
                        'address_id'                => $order->address_id,
                        'created_at'                => $order->created_at,
                        'updated_at'                => $order->updated_at,
                    ];

                    $created_order = Order::create($new_order);
                    echo 'Se creo order id: '.$created_order->id.' </br>';

                    Self::attach_articles($created_order, $order);
                } else {
                    echo 'NO se creo order id: '.$order->id.' </br>';
                }
            }
        }
    }

    
    static function attach_articles($created_order, $order) {
        foreach ($order->articles as $article) {
            $created_order->articles()->attach($article->id, [
                'cost'          => $article->pivot->cost,
                'price'         => $article->pivot->price,
                'amount'        => $article->pivot->amount,
                'notes'         => $article->pivot->notes,
                'variant_id'    => $article->pivot->variant_id,
                'color_id'      => $article->pivot->color_id,
                'size_id'       => $article->pivot->size_id,
                'address_id'    => $article->pivot->address_id,
                'with_dolar'    => $article->pivot->with_dolar,
            ]);
        }
    }

}