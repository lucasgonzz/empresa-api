<?php

namespace App\Http\Controllers\Helpers\database;

use App\Http\Controllers\Helpers\DatabaseHelper;
use App\Models\CurrentAcount;
use App\Models\Provider;
use App\Models\ProviderPriceList;

class DatabaseProviderHelper {

    static function copiar_providers($user, $bbdd_destino, $from_id) {
        $provider = Provider::where('user_id', $user->id)
                        ->where('id', '>=', $from_id)
                        ->with('provider_price_lists')
                        ->get();

        DatabaseHelper::set_user_conecction($bbdd_destino);

        foreach ($provider as $provider) {
            $created_provider = Provider::create([
                'id'                    => $provider->id,
                'num'                   => $provider->num,
                'name'                  => $provider->name,  
                'phone'                 => $provider->phone, 
                'address'               => $provider->address,   
                'email'                 => $provider->email, 
                'razon_social'          => $provider->razon_social,  
                'cuit'                  => $provider->cuit,  
                'observations'          => $provider->observations,  
                'location_id'           => $provider->location_id,   
                'iva_condition_id'      => $provider->iva_condition_id,  
                'percentage_gain'       => $provider->percentage_gain,   
                'dolar'                 => $provider->dolar, 
                'saldo'                 => $provider->saldo, 
                'comercio_city_user_id' => $provider->comercio_city_user_id, 
                'user_id'               => $provider->user_id,
            ]);

            echo 'Se creo provider id '.$created_provider->id.' </br>';

            foreach ($provider->provider_price_lists as $price_list) {
                ProviderPriceList::create($price_list->toArray());
                echo 'Se creo lista de precios '.$price_list->name.' </br>';
            }

            Self::copy_current_acounts($created_provider, $bbdd_destino);

            echo '------------------- </br>';
        }
    }

    static function copy_current_acounts($created_provider, $bbdd_destino) {

        DatabaseHelper::set_user_conecction(env('DB_DATABASE'), false);

        $current_acounts = CurrentAcount::where('provider_id', $created_provider->id)
                        ->orderBy('id', 'ASC')
                        // ->where('id', '>=', $from_id)
                        // ->with('pagado_por', 'pagando_a')
                        ->get();

        DatabaseHelper::set_user_conecction($bbdd_destino);

        foreach ($current_acounts as $current_acount) {

            $created_current_acount = CurrentAcount::create([
                'id' => $current_acount->id,
                'detalle' => $current_acount->detalle, 
                'description' => $current_acount->description,
                'debe' => $current_acount->debe,
                'haber' => $current_acount->haber,
                'saldo' => $current_acount->saldo,
                'status' => $current_acount->status,
                'pagandose' => $current_acount->pagandose,
                'num_receipt' => $current_acount->num_receipt,
                'to_pay_id' => $current_acount->to_pay_id,
                'user_id' => $current_acount->user_id,
                'numero_orden_de_compra' => $current_acount->numero_orden_de_compra,
                'client_id' => $current_acount->client_id,
                'commissioner_id' => $current_acount->commissioner_id,
                'seller_id' => $current_acount->seller_id,
                'sale_id' => $current_acount->sale_id,
                'budget_id' => $current_acount->budget_id,
                'order_production_id' => $current_acount->order_production_id,
                'provider_id' => $current_acount->provider_id,
                'provider_order_id' => $current_acount->provider_order_id,
                'current_acount_payment_method_id' => $current_acount->current_acount_payment_method_id,
                'employee_id' => $current_acount->employee_id,
                'created_at' => $current_acount->created_at,
                'updated_at' => $current_acount->updated_at,
            ]);

            echo 'Se creo current_acount id '.$created_current_acount->id.' </br>';

            foreach ($current_acount->current_acount_payment_methods as $payment_method) {

                $created_current_acount->current_acount_payment_methods()->attach($payment_method->id, [
                    'amount'                        => $payment_method->pivot->amount,
                    'bank'                          => $payment_method->pivot->bank,
                    'payment_date'                  => $payment_method->pivot->payment_date,
                    'num'                           => $payment_method->pivot->num,
                    'credit_card_id'                => $payment_method->pivot->credit_card_id,
                    'credit_card_payment_plan_id' => $payment_method->pivot->credit_card_payment_plan_id,
                    'user_id'                   => $client->user_id,
                ]);

                echo 'Se agergo metodo de pago '.$payment_method->name.' </br>';
            }
            echo '------------ </br>';

        }
    }
}