<?php

namespace App\Http\Controllers\Helpers\database;

use App\Http\Controllers\Helpers\DatabaseHelper;
use App\Models\Address;
use App\Models\Buyer;
use App\Models\Message;

class DatabaseBuyerHelper {

    static function copiar_buyers($user, $bbdd_destino) {
        $buyers = Buyer::where('user_id', $user->id)
                        ->with('messages', 'addresses')
                        ->get();

        DatabaseHelper::set_user_conecction($bbdd_destino);

        foreach ($buyers as $buyer) {
            $created_buyer = Buyer::create([
                'id'                        => $buyer->id,
                'num'                       => $buyer->num,
                'name'                      => $buyer->name,
                'email'                     => $buyer->email,
                'phone'                     => $buyer->phone,
                'password'                  => $buyer->password,
                'comercio_city_client_id'   => $buyer->comercio_city_client_id,
                'user_id'                   => $buyer->user_id,
            ]);

            foreach ($buyer->messages as $message) {
                Message::create($message->toArray());
            }

            // foreach ($buyer->addresses as $address) {
            //     Address::create($address->toArray());
            // }
        }
    }
}