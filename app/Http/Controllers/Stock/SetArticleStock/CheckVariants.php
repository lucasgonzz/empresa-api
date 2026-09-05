<?php

namespace App\Http\Controllers\Stock\SetArticleStock;

use App\Models\ArticleVariant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CheckVariants {

    static function check_article_variant($stock_movement, $article) {

        if (
            !is_null($stock_movement->article_variant_id)
            && $stock_movement->article_variant_id != 0
        ) {

            $article_variant = ArticleVariant::find($stock_movement->article_variant_id);

            if (is_null($article_variant)) {

                Log::warning('CheckVariants: el movimiento '.$stock_movement->id.' apunta a la variante '.$stock_movement->article_variant_id.', que no existe. No se toca ningun stock.');
                return;
            }

            if (!is_null($stock_movement->from_address_id)) {

                Self::procesar_from_address($stock_movement, $article_variant);
            }

            if (!is_null($stock_movement->to_address_id)) {

                Self::procesar_to_address($stock_movement, $article_variant);
            }

            /*
                El stock global de la variante se mueve siempre; si la variante reparte por
                depositos, setArticleStockFromAddresses() lo vuelve a calcular despues sumando sus
                depositos. La suma la hace el SQL, por el mismo motivo que en CheckGlobalStock: dos
                movimientos simultaneos no se pisan.
            */
            DB::table('article_variants')
                ->where('id', $article_variant->id)
                ->update([
                    'stock' => DB::raw('COALESCE(stock, 0) + ('.sprintf('%.4F', (float)$stock_movement->amount).')'),
                ]);

        }
    }

    static function procesar_from_address($stock_movement, $article_variant) {

        /*
            Si la variante NO reparte por depositos, el origen no le dice nada: el stock que sale
            se descuenta del global de la variante (mas abajo). Abrirle un deposito con cantidad
            negativa la convertia en "variante con depositos" y setArticleStockFromAddresses()
            pisaba su stock con esa unica fila negativa.
        */
        if (count($article_variant->addresses) == 0) {
            return;
        }

        $article_variant_address = null;

        foreach ($article_variant->addresses as $address) {

            if ($address->id == $stock_movement->from_address_id) {
                $article_variant_address = $address;
            }
        }

        $amount = Self::get_amount_for_from_address($stock_movement);

        if (is_null($article_variant_address)) {

            /*
                La variante reparte por depositos pero todavia no tiene fila para este: se abre con
                la cantidad del movimiento (negativa si es una venta), igual que en CheckFromAddress.
            */
            $article_variant->addresses()->attach($stock_movement->from_address_id, [
                'amount'    => $amount,
            ]);

        } else {

            Self::sumar_al_deposito($article_variant, $stock_movement->from_address_id, (float)$amount);
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
                Misma guarda que CheckToAddress::puede_abrir_el_primer_deposito(): a una variante
                que lleva stock global no se le abre un deposito por una devolucion o un ingreso
                cualquiera, porque desde ese momento su stock pasaria a ser la suma de depositos
                (o sea, solo lo devuelto).
            */
            if (!Self::puede_abrir_el_primer_deposito($stock_movement, $article_variant)) {

                Log::info('CheckVariants: la variante '.$article_variant->id.' lleva stock global ('.$article_variant->stock.') y el concepto no reparte stock: el movimiento '.$stock_movement->id.' se aplica al stock global de la variante');
                return;
            }

            $article_variant->addresses()->attach($stock_movement->to_address_id, [
                'amount'    => $stock_movement->amount,
            ]);

        } else {

            Self::sumar_al_deposito($article_variant, $stock_movement->to_address_id, (float)$stock_movement->amount);
            Log::info('Ya tenia en la direccion, se sumo '.$stock_movement->amount);
        }
    }

    /**
     * Si a esta variante se le puede abrir el primer deposito con este movimiento. Espejo de
     * CheckToAddress::puede_abrir_el_primer_deposito() para variantes.
     *
     * @param  \App\Models\StockMovement  $stock_movement
     * @param  \App\Models\ArticleVariant $article_variant
     * @return bool
     */
    static function puede_abrir_el_primer_deposito($stock_movement, $article_variant) {

        if (count($article_variant->addresses) >= 1) {
            return true;
        }

        if (is_null($article_variant->stock) || (float)$article_variant->stock == 0) {
            return true;
        }

        $concepto = $stock_movement->concepto_movement;

        if (is_null($concepto)) {
            return false;
        }

        return in_array($concepto->name, CheckToAddress::CONCEPTOS_QUE_REPARTEN);
    }

    /**
     * Suma (o resta) sobre el pivot del deposito de la variante en una sola sentencia SQL.
     * Ver CheckToAddress::sumar_al_deposito() para el porque.
     *
     * @param  \App\Models\ArticleVariant $article_variant
     * @param  int                        $address_id
     * @param  float                      $delta
     * @return void
     */
    static function sumar_al_deposito($article_variant, $address_id, $delta) {

        DB::table('address_article_variant')
            ->where('article_variant_id', $article_variant->id)
            ->where('address_id', $address_id)
            ->update([
                'amount'     => DB::raw('COALESCE(amount, 0) + ('.sprintf('%.4F', $delta).')'),
                'updated_at' => now(),
            ]);
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
