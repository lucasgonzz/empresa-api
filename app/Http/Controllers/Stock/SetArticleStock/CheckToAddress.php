<?php

namespace App\Http\Controllers\Stock\SetArticleStock;

use Illuminate\Support\Facades\Log;

class CheckToAddress {
    

    /*
        *   Aca se aumenta el stock del deposito destino
            En caso de que sea:

            * Modificacion de stock (desde el modal de article)
            
            * Pedido de proveedor
            * Actualizacion Pedido de proveedor
            
            * Movimiento de depositos
            
            * Importacion de excel
            
            * Actualizacion de venta
            * Se elimino de una venta
            * Se elimino la venta
            
            * Nota de credito

            * Ingreso manual

            * Creacion de deposito
            * Actualizacion de deposito
            * Mov entre depositos

            * Produccion
    */
    static function check_to_address($stock_movement, $article) {

        if (
            !is_null($stock_movement->to_address_id)
            && $stock_movement->to_address_id != 0
            && $stock_movement->concepto_movement->name != 'Se elimino la venta'
            // && count($article->addresses) >= 1
            // && is_null($stock_movement->article_variant_id)
            // && (
            //         $articleHasAddresses()
            //         || (
            //             is_null($article->stock)
            //             || $article->stock == 0
            //         )
            //     )
            ) {

            $article->load('addresses');
            
            $to_address = null;

            foreach ($article->addresses as $address) {
                if ($address->id == $stock_movement->to_address_id) {
                    $to_address = $address;
                }
            }
            
            if (is_null($to_address)) {

                Log::info('Agregando address_id: '.$stock_movement->to_address_id.' al articulo '.$article->name);

                /*
                    Si la direccion destino es null  
                    se le attach esa direccion y se le pone como cantidad inicial la cantidad del movimineto de deposito
                    Ya que siempre va a ser positiva
                */
                $article->addresses()->attach($stock_movement->to_address_id, [
                    'amount'    => $stock_movement->amount,
                ]);

            } else {

                $new_amount = $to_address->pivot->amount + $stock_movement->amount;
                $article->addresses()->updateExistingPivot($stock_movement->to_address_id, [
                    'amount'    => $new_amount,
                ]);
            }
        }
    }

}