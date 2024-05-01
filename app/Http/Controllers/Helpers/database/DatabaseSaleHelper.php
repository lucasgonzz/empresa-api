<?php

namespace App\Http\Controllers\Helpers\database;

use App\Http\Controllers\Helpers\DatabaseHelper;
use App\Models\AfipTicket;
use App\Models\Sale;
use App\Models\User;

class DatabaseSaleHelper {

    static function copiar_ventas($user, $bbdd_destino, $from_id) {

        if (!is_null($user)) {

            $sales = Sale::where('user_id', $user->id)
                                ->orderBy('id', 'ASC')
                                ->where('id', '>=', $from_id)
                                ->with('discounts', 'surchages', 'services', 'articles', 'afip_ticket')
                                ->get();

            DatabaseHelper::set_user_conecction($bbdd_destino);
            
            foreach ($sales as $sale) {

                $sale_ya_creada = Sale::find($sale->id);

                if (is_null($sale_ya_creada)) {
                    
                    $new_sale = [
                        'id'                                => $sale->id,
                        'num'                               => $sale->num,
                        'client_id'                         => $sale->client_id,
                        'sale_type_id'                      => $sale->sale_type_id,
                        'observations'                      => $sale->observations,
                        'address_id'                        => $sale->address_id,
                        'current_acount_payment_method_id'  => $sale->current_acount_payment_method_id,
                        'afip_information_id'               => $sale->afip_information_id,
                        'save_current_acount'               => $sale->save_current_acount,
                        'price_type_id'                     => $sale->price_type_id,
                        'discounts_in_services'             => $sale->discounts_in_services,
                        'surchages_in_services'             => $sale->surchages_in_services,
                        'employee_id'                       => $sale->employee_id,
                        'to_check'                          => $sale->to_check,
                        'user_id'                           => $sale->user_id,
                        'printed'                           => $sale->printed,
                        'created_at'                        => $sale->created_at,
                        'updated_at'                        => $sale->updated_at,
                    ];

                    $created_sale = Sale::create($new_sale);
                    echo 'Se creo sale id: '.$created_sale->id.' </br>';

                    Self::attach_sale_articles($created_sale, $sale);
                    
                    Self::attach_services($created_sale, $sale);

                    Self::attach_discounts($created_sale, $sale);
                    Self::attach_surchages($created_sale, $sale);

                    Self::attach_afip_ticket($created_sale, $sale);
                }
            }
        }
    }

    static function attach_services($created_sale, $sale) {
        foreach ($sale->services as $service) {

            $created_sale->services()->attach($service->id, [
                'price' => $service->pivot->price_vender,
                'amount' => $service->pivot->amount,
                'returned_amount'   => $service->pivot->returned_amount,
                'discount' => $service->pivot->returned_amount,
            ]);

        }
    }

    static function attach_surchages($created_sale, $sale) {
        foreach ($sale->surchages as $surchage) {
            $created_sale->surchages()->attach($surchage->id, [
                'percentage'    => $surchage->pivot->percentage,
            ]);
        }
    }

    static function attach_afip_ticket($created_sale, $sale) {
        if (!is_null($sale->afip_ticket)) {
            $afip_ticket = $sale->afip_ticket->toArray();
            if ($afip_ticket['cae_expired_at'] == '') {
                $afip_ticket['cae_expired_at'] = null;
            }
            AfipTicket::create($afip_ticket);
        }
    }


    static function attach_discounts($created_sale, $sale) {
        foreach ($sale->discounts as $discount) {
            $created_sale->discounts()->attach($discount->id, [
                'percentage'    => $discount->pivot->percentage,
            ]);
        }
    }

    static function attach_sale_articles($created_sale, $sale) {
        foreach ($sale->articles as $article) {
            $created_sale->articles()->attach($article->id, [
                'amount'           => $article->pivot->amount,
                'cost'             => $article->pivot->cost,  
                'price'            => $article->pivot->price,  
                'returned_amount'  => $article->pivot->returned_amount,
                'delivered_amount' => $article->pivot->delivered_amount,
                'discount'         => $article->pivot->discount,  
                'checked_amount'   => $article->pivot->checked_amount,
                'created_at'       => $article->pivot->created_at,
            ]);
        }
    }

}