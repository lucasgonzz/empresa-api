<?php

namespace App\Http\Controllers\Stock\SetArticleStock;

use App\Models\ArticleVariant;
use Illuminate\Support\Facades\Log;

class CheckVariants {
    
    static function check_article_variant($stock_movement, $article) {

        if (
            !is_null($stock_movement->article_variant_id)
            && $stock_movement->article_variant_id != 0
        ) {

            $article_variant = ArticleVariant::find($stock_movement->article_variant_id);
            
            if (!is_null($stock_movement->from_address_id)) {

                Self::procesar_from_address($stock_movement, $article_variant);
            } 

            if (!is_null($stock_movement->to_address_id)) {

                Self::procesar_to_address($stock_movement, $article_variant);
            } 


            $article_variant->stock += $stock_movement->amount;
            $article_variant->save();

        }
    }

    static function procesar_from_address($stock_movement, $article_variant) {

        $article_variant_address = null;

        foreach ($article_variant->addresses as $address) {

            if ($address->id == $stock_movement->from_address_id) {
                $article_variant_address = $address;
            }
        }
        
        $amount = Self::get_amount_for_from_address($stock_movement);
        
        if (is_null($article_variant_address)) {

            /*
                Si la direccion destino es NULL  
                se le attach esa direccion y se le pone como cantidad inicial la cantidad del movimineto de deposito
                Ya que siempre va a ser positiva
            */
            $article_variant->addresses()->attach($stock_movement->from_address_id, [
                'amount'    => $amount,
            ]);

        } else {

            $new_amount = $article_variant_address->pivot->amount + $amount;

            $article_variant->addresses()->updateExistingPivot($stock_movement->from_address_id, [
                'amount'    => $new_amount,
            ]);
        }
    }

    static function procesar_to_address($stock_movement, $article_variant) {
        
        $article_variant_address = null;

        foreach ($article_variant->addresses as $address) {

            if ($address->id == $stock_movement->to_address_id) {
                $article_variant_address = $address;
            }
        }
        
        if (is_null($article_variant_address)) {

            /*
                Si la direccion destino es null  
                se le attach esa direccion y se le pone como cantidad inicial la cantidad del movimineto de deposito
                Ya que siempre va a ser positiva
            */
            $article_variant->addresses()->attach($stock_movement->to_address_id, [
                'amount'    => $stock_movement->amount,
            ]);

        } else {

            $new_amount = $article_variant_address->pivot->amount + $stock_movement->amount;

            $article_variant->addresses()->updateExistingPivot($stock_movement->to_address_id, [
                'amount'    => $new_amount,
            ]);
            Log::info('Ya tenia en la direccion, se va a actualizar la cantidad con '.$new_amount);
        }
    }


    /**
     * Devuelve el monto a aplicar sobre el deposito de origen, para variantes de articulo.
     *
     * Copia separada del metodo homonimo de CheckFromAddress: aplica el mismo criterio de
     * inversion de signo para los movimientos entre depositos.
     *
     * @param  \App\Models\StockMovement  $stock_movement  Movimiento de stock recien guardado.
     * @return float
     */
    static function get_amount_for_from_address($stock_movement) {

        // Relacion con el concepto del movimiento. Puede venir null cuando el concepto no se
        // pudo resolver por nombre (ver SetConcepto).
        $concepto = $stock_movement->concepto_movement;

        if (is_null($concepto)) {

            // Decision conservadora: sin concepto se devuelve el monto SIN invertir, igual que
            // en CheckFromAddress. Los movimientos entre depositos son casos puntuales; ventas,
            // compras e importaciones son las que no necesitan la inversion.
            Log::warning('CheckVariants: stock_movement sin concepto, se usa el amount sin invertir. stock_movement_id: '.$stock_movement->id.' concepto_stock_movement_id: '.$stock_movement->concepto_stock_movement_id);
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