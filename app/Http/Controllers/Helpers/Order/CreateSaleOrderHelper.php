<?php

namespace App\Http\Controllers\Helpers\Order;

use App\Http\Controllers\Helpers\SaleHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Models\Sale;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class CreateSaleOrderHelper {

    static function save_sale($order, $instance, $from_tienda_nube = false, $from_meli = false, $user = null) {
        if (
            $from_tienda_nube
            || $from_meli
            || (
                $order->order_status->name == 'Confirmado' 
               && Self::saveSaleAfterFinishOrder() 
            )
        ) {

            $to_check = UserHelper::hasExtencion('check_sales', $user);
            
            $sale = Self::createSale($order, $instance, $to_check, $from_tienda_nube, $from_meli, $user);

            Self::attach_sale_properties($order, $sale, $from_tienda_nube, $from_meli);

            Log::info('se guardo venta para el pedido online, sale_id: '.$sale->id);
        }
    }

    static function attach_sale_properties($order, $sale, $from_tienda_nube, $from_meli) {

        $request = new \Illuminate\Http\Request();

        $request->items = [];

        foreach ($order->articles as $article) {
            $request->items[] = [
                'id'                => $article->id,
                'name'              => $article->name,
                'amount'            => $article->pivot->amount,
                'cost'              => $article->pivot->cost ?? $article->cost,
                'price_vender'      => $article->pivot->price,
                'is_article'        => true
            ];
        }


        if ($from_meli) {
            $request->employee_id = null;
        }


        if (
            !$from_tienda_nube
            && !$from_meli
        ) {

            foreach ($order->promocion_vinotecas as $promo) {
                $request->items[] = [
                    'id'                => $promo->id,
                    'name'              => $promo->name,
                    'cost'              => $promo->pivot->cost,
                    'amount'            => $promo->pivot->amount,
                    'price_vender'      => $promo->pivot->price,
                    'is_promocion_vinoteca'        => true
                ];
            }
        }

        $request->discounts = [];
        $request->surchages = [];

        SaleHelper::attachProperies($sale, $request);
    }
    

    static function createSale($order, $instance, $to_check = false, $from_tienda_nube, $from_meli, $user) {
        $client_id = null;


        if ($user) {
            $num = $instance->num('sales', $user->id, 'user_id', $user->id);
        } else {
            $num = $instance->num('sales');
        }
        
        if (
            !$from_tienda_nube
            && !$from_meli
            && !is_null($order->buyer->comercio_city_client)
        ) {
            $client_id = $order->buyer->comercio_city_client_id;
        }

        $terminada = Self::is_terminada($order, $to_check);

        $sale = Sale::create([
            'user_id'               => $order->user_id,
            'buyer_id'              => $order->buyer_id,
            'client_id'             => $client_id,
            'to_check'              => $to_check,
            'terminada'             => $terminada,
            'terminada_at'          => $terminada ? Carbon::now() : null,
            'num'                   => $num,
            'save_current_acount'   => 1,
            'order_id'              => ($from_tienda_nube || $from_meli) ? null : $order->id,
            'tienda_nube_order_id'  => $from_tienda_nube ? $order->id : null,
            'meli_order_id'         => $from_meli ? $order->id : null,
            'total'                 => $order->total,
            'address_id'            => $order->address_id,
            'fecha_entrega'         => $order->fecha_entrega,
            'seller_id'             => $order->seller_id,
            'moneda_id'             => 1,
            'employee_id'           => $from_meli ? null : SaleHelper::getEmployeeId(),
            'created_at'            => $from_meli ? $order->created_at : Carbon::now(),
        ]);

        if (
            !is_null($sale->client)
            && !is_null($sale->client->price_type_id)
        ) {

            $sale->price_type_id = $sale->client->price_type_id;
            $sale->save();
        }

        // Self::attach_articles($sale, $order->articles);

        return $sale;
    }

    static function is_terminada($order, $to_check) {

        if ($to_check) {
            return 0;
        }

        if ($order->fecha_entrega) {
            return 0;
        }

        return 1;
    }


    static function saveSaleAfterFinishOrder() {
        $user = UserHelper::getFullModel();
        return $user->online_configuration->save_sale_after_finish_order;
    }

    // static function attach_articles($sale, $articles) {
    //     foreach ($articles as $article) {
    //         $sale->articles()->attach($article->id, [
    //                                         'amount' => $article->pivot->amount,
    //                                         'cost' => isset($article->pivot->cost)
    //                                                     ? $article->pivot->cost
    //                                                     : null,
    //                                         'price' => $article->pivot->price,
    //                                     ]);
            
    //     }
    // }

}