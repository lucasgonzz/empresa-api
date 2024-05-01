<?php

namespace App\Http\Controllers\Helpers\database;

use App\Http\Controllers\Helpers\DatabaseHelper;
use App\Models\ProviderOrder;
use App\Models\ProviderOrderAfipTicket;
use App\Models\ProviderOrderExtraCost;

class DatabaseProviderOrderHelper {

    static function copiar_provider_orders($user, $bbdd_destino, $from_id) {
        $provider_orders = ProviderOrder::where('user_id', $user->id)
                        ->with('articles', 'provider_order_afip_tickets', 'provider_order_extra_costs')
                        ->where('id', '>=', $from_id)
                        ->get();

        DatabaseHelper::set_user_conecction($bbdd_destino);

        foreach ($provider_orders as $provider_order) {
            $created_provider_order = ProviderOrder::create([
                'id'                                       => $provider_order->id,
                'num'                                       => $provider_order->num,
                'total_with_iva'                            => $provider_order->total_with_iva,
                'total_from_provider_order_afip_tickets'    => $provider_order->total_from_provider_order_afip_tickets,
                'provider_id'                               => $provider_order->provider_id,
                'provider_order_status_id'                  => $provider_order->provider_order_status_id,
                'days_to_advise'                            => $provider_order->days_to_advise,
                'user_id'                                   => $provider_order->user_id
            ]);

            foreach ($provider_order->articles as $article) {
                $created_provider_order->articles()->attach($article->id, [
                    'amount'            => $article->pivot->amount,
                    'notes'             => $article->pivot->notes,
                    'received'          => $article->pivot->received,
                    'cost'              => $article->pivot->cost,                 
                    'received_cost'     => $article->pivot->received_cost,
                    'update_cost'       => $article->pivot->update_cost,
                    'update_provider'   => $article->pivot->update_provider,
                    'cost_in_dollars'   => $article->pivot->cost_in_dollars,
                    'add_to_articles'   => $article->pivot->add_to_articles,
                    'address_id'        => $article->pivot->address_id,
                    'iva_id'            => $article->pivot->iva_id,
                ]);
            }

            foreach ($provider_order->provider_order_afip_tickets as $provider_order_afip_ticket) {
                ProviderOrderAfipTicket::create($provider_order_afip_ticket->toArray());
            }

            foreach ($provider_order->provider_order_extra_costs as $provider_order_extra_cost) {
                ProviderOrderExtraCost::create($provider_order_extra_cost->toArray());
            }

            echo 'Se creo provider_order id '.$created_provider_order->id.' </br>';
        }
    }
}