<?php

namespace App\Http\Controllers\Helpers\database;

use App\Http\Controllers\Helpers\CurrentAcountHelper;
use App\Http\Controllers\Helpers\DatabaseHelper;
use App\Models\Client;
use App\Models\CurrentAcount;

class DatabaseClientsHelper {

    static function copiar_clients($user, $bbdd_destino, $from_id) {
        $clients = Client::where('user_id', $user->id)
                        ->where('id', '>=', $from_id)
                        ->withTrashed()
                        ->orderBy('created_at', 'ASC')
                        ->get();


        foreach ($clients as $client) {
            
            DatabaseHelper::set_user_conecction($bbdd_destino);
            
            $created_client = Client::create([
                'id'                          => $client->id,
                'num'                         => $client->num,
                'name'                        => $client->name,
                'email'                       => $client->email,
                'phone'                       => $client->phone,
                'address'                     => $client->address,
                'cuil'                        => $client->cuil,
                'cuit'                        => $client->cuit,
                'dni'                         => $client->dni,
                'razon_social'                => $client->razon_social,
                'iva_condition_id'            => $client->iva_condition_id,
                'price_type_id'               => $client->price_type_id,
                'location_id'                 => $client->location_id,
                'description'                 => $client->description,
                'saldo'                       => $client->saldo,
                'comercio_city_user_id'       => $client->comercio_city_user_id,
                'seller_id'                   => $client->seller_id,
                'user_id'                     => $client->user_id,
                'created_at'                  => $client->created_at,
                'updated_at'                  => $client->updated_at,
            ]);

            echo 'Se creo client id '.$created_client->id.' </br>';

            Self::copy_current_acounts($created_client, $bbdd_destino);
            // CurrentAcountHelper::checkPagos('client', $created_client->id, true);

        }
    }

    static function copy_current_acounts($created_client, $bbdd_destino) {

        DatabaseHelper::set_user_conecction(env('DB_DATABASE'), false);

        $current_acounts = CurrentAcount::where('client_id', $created_client->id)
                                            ->orderBy('id', 'ASC')
                                            ->with('current_acount_payment_methods')
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
                    'credit_card_payment_plan_id'   => $payment_method->pivot->credit_card_payment_plan_id,
                    'user_id'                       => $created_client->user_id,
                ]);

                echo 'Se agergo metodo de pago '.$payment_method->name.' </br>';
            }
            echo '------------ </br>';

        }
    }
}