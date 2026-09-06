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

            /*
                Un movimiento de VARIANTE no toca el pivot del articulo: lo lleva CheckVariants y
                despues setArticleStockFromAddresses() reconstruye los depositos del articulo
                sumando los de sus variantes. Mismo criterio que CheckToAddress.
            */
            if (
                !is_null($stock_movement->article_variant_id)
                && $stock_movement->article_variant_id != 0
            ) {
                return;
            }

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
                    Porque si es una venta, va a ser un valor negativo.
                    La suma la hace el SQL, no PHP: ver CheckToAddress::sumar_al_deposito().
                */
                CheckToAddress::sumar_al_deposito($article, $from_address->id, (float)Self::get_amount_for_from_address($stock_movement));

            } else {

                /*
                    El articulo reparte por depositos pero todavia no tiene fila para este. Se abre
                    con la cantidad del movimiento (negativa si es una venta): el stock de un deposito
                    puede quedar en negativo, y asi el usuario ve de donde salio.
                */
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
