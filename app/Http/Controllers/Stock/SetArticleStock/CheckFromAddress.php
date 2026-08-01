<?php

namespace App\Http\Controllers\Stock\SetArticleStock;
use Illuminate\Support\Facades\Log;

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
            
            Log::info('Actualizando stock from addresses para '.$article->name.'. Addresses:');
            Log::info($article->addresses);
            
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


    /**
     * Devuelve el monto a aplicar sobre el deposito de origen.
     *
     * En los movimientos entre depositos el monto se invierte, porque lo que entra en el
     * deposito destino tiene que salir del de origen.
     *
     * @param  \App\Models\StockMovement  $stock_movement  Movimiento de stock recien guardado.
     * @return float
     */
    static function get_amount_for_from_address($stock_movement) {

        // Relacion con el concepto del movimiento. Puede venir null cuando el concepto no se
        // pudo resolver por nombre (ver SetConcepto).
        $concepto = $stock_movement->concepto_movement;

        if (is_null($concepto)) {

            // Decision conservadora: sin concepto se devuelve el monto SIN invertir. Los
            // movimientos entre depositos son casos puntuales y explicitos; ventas, compras e
            // importaciones (la gran mayoria) son las que no necesitan la inversion.
            Log::warning('CheckFromAddress: stock_movement sin concepto, se usa el amount sin invertir. stock_movement_id: '.$stock_movement->id.' concepto_stock_movement_id: '.$stock_movement->concepto_stock_movement_id);
            return $stock_movement->amount;
        }

        if (
            $concepto->name == 'Mov entre depositos'
            || $concepto->name == 'Mov manual entre depositos'
        ) {
            return (float)-$stock_movement->amount;
        }

        return $stock_movement->amount;
    }

}