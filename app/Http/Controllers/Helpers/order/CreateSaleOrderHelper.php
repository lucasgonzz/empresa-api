<?php

namespace App\Http\Controllers\Helpers\Order;

use App\Http\Controllers\Helpers\SaleHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Models\Sale;
use Illuminate\Support\Facades\Log;

class CreateSaleOrderHelper {

    static function save_sale($order, $instance) {
        if (
        	$order->order_status->name == 'Confirmado' 
        	&& Self::saveSaleAfterFinishOrder() 
        ) {

            $to_check = UserHelper::hasExtencion('check_sales');
            
            $sale = Self::createSale($order, $instance, $to_check);

            Self::attach_sale_properties($order, $sale);

            Log::info('se guardo venta para el pedido online, sale_id: '.$sale->id);
        }
    }

    static function attach_sale_properties($order, $sale) {

        $request = new \Illuminate\Http\Request();

        $request->items = [];

        foreach ($order->articles as $article) {
        	$request->items[] = [
                'id'                => $article->id,
                'cost'              => $article->cost,
        		'amount'		    => $article->pivot->amount,
        		'cost'			    => $article->pivot->cost,
        		'price_vender'		=> $article->pivot->price,
                'is_article'        => true
        	];
        }

        $request->discounts = [];
        $request->surchages = [];

        SaleHelper::attachProperies($sale, $request);
    }
	

    static function createSale($order, $instance, $to_check = false) {
        $client_id = null;
        
        if (!is_null($order->buyer->comercio_city_client)) {
            $client_id = $order->buyer->comercio_city_client_id;
        }

        $sale = Sale::create([
            'user_id'               => $instance->userId(),
            'buyer_id'              => $order->buyer_id,
            'client_id'             => $client_id,
            'to_check'              => $to_check,
            'terminada'             => !$to_check,
            'num'                   => $instance->num('sales'),
            'save_current_acount'   => 1,
            'order_id'              => $order->id,
            'total'                 => $order->total,
            'address_id'            => $order->address_id,
            'employee_id'           => SaleHelper::getEmployeeId(),
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