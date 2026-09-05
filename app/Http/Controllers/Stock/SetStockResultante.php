<?php

namespace App\Http\Controllers\Stock;

use App\Models\ConceptoStockMovement;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SetStockResultante  {

    /**
     * Persiste el stock_resultante de un movimiento de stock: el stock REAL del articulo despues
     * de aplicar el movimiento.
     *
     * 🔴 Hasta la auditoria de stock (5/9/2026) el stock_resultante se ENCADENABA: era el
     * stock_resultante del movimiento anterior mas el amount de este, salvo cuatro conceptos que
     * lo copiaban del stock del articulo. Esa cadena arrastraba para siempre cualquier desvio
     * historico (un stock cargado a mano antes de que existieran los movimientos, un movimiento
     * que no toco el stock, una correccion directa en la base) y hacia que `check_stocks` denunciara
     * como "mal" a articulos cuyo stock estaba perfecto. Ahora el numero que se guarda es el que
     * el usuario ve en el listado un segundo despues del movimiento, y las dos cosas no pueden
     * discrepar porque salen de la misma fila.
     *
     * Se lee de la base y no de `$article`, porque SetArticleStock ya actualizo la fila con SQL y
     * el modelo en memoria puede estar atrasado.
     *
     * @param  \App\Models\StockMovement  $stock_movement  Movimiento de stock recien guardado.
     * @param  \App\Models\Article        $article         Articulo afectado por el movimiento.
     * @return void
     */
    static function set_stock_resultante($stock_movement, $article) {

        if (is_null($article)) {
            $stock_movement->stock_resultante = $stock_movement->amount;
            $stock_movement->save();
            return;
        }

        $stock_real = DB::table('articles')
                        ->where('id', $article->id)
                        ->value('stock');

        if (is_null($stock_real)) {

            // Sin stock en la fila (el articulo no lleva stock): el unico dato que hay es el amount.
            $stock_movement->stock_resultante = $stock_movement->amount;

        } else {

            $stock_movement->stock_resultante = (float)$stock_real;

            // El modelo en memoria sigue en uso (avisos, carritos, sync): se lo deja al dia.
            $article->stock = (float)$stock_real;
        }

        $stock_movement->save();

        Self::set_stock_actual_in_observations($stock_movement, $article);

    }

    static function set_stock_actual_in_observations($stock_movement, $article) {

        if (!is_null($article)) {

            if (!is_null($stock_movement->observations)) {
                $stock_movement->observations .= ' - '.$article->stock;
            } else {
                $stock_movement->observations = $article->stock;
            }
            $stock_movement->save();
        }

    }

 }
