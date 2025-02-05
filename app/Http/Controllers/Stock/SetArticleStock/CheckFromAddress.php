<?php

namespace App\Http\Controllers\Stock\SetArticleStock;

class CheckFromAddress {


    /*
        *   Aca se descuenta el stock del deposito de origen
            En caso de que sea:

            * Una venta
            * Un movimiento de deposito
            * Inusmo de produccion
    */
    static function check_from_address($stock_movement, $article) {

        if (
            !is_null($stock_movement->from_address_id) 
            && $stock_movement->from_address_id != 0 
            && count($article->addresses) >= 1
        ) {
            
            $article->load('addresses');

            $from_address = null;

            foreach ($article->addresses as $address) {

                if ($address->id == $stock_movement->from_address_id) {
                    $from_address = $address;
                }
            }

            if (!is_null($from_address)) {

                /* 
                    Ahora se va a sumar la cantidad
                    Porque si es una venta, va a ser un valor negativo
                */
                $new_amount = (float)$from_address->pivot->amount + Self::get_amount_for_from_address($stock_movement);

                $article->addresses()->updateExistingPivot($from_address->id, [
                    'amount'    => $new_amount,
                ]);

            } else {

                $article->addresses()->attach($stock_movement->from_address_id, [
                    'amount'    => Self::get_amount_for_from_address($stock_movement),
                ]);
            }
        }
    }


    function get_amount_for_from_address($stock_movement) {

        $concepto = $stock_movement->concepto;

        if (
            $concepto->name == 'Mov entre depositos'
            || $concepto->name == 'Mov manual entre depositos'
        ) {
            return (float)-$stock_movement->amount;
        }

        return $stock_movement->amount;
    }

}