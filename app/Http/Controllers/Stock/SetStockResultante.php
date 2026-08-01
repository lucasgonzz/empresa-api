<?php

namespace App\Http\Controllers\Stock;

use App\Models\ConceptoStockMovement;
use App\Models\StockMovement;
use Illuminate\Support\Facades\Log;

class SetStockResultante  {

    /**
     * Calcula y persiste el stock_resultante de un movimiento de stock.
     *
     * @param  \App\Models\StockMovement  $stock_movement  Movimiento de stock recien guardado.
     * @param  \App\Models\Article        $article         Articulo afectado por el movimiento.
     * @return void
     */
    static function set_stock_resultante($stock_movement, $article) {

        // Relacion con el concepto del movimiento. Puede venir null cuando el concepto no se
        // pudo resolver por nombre (ver SetConcepto).
        $concepto_movement = $stock_movement->concepto_movement;

        // Nombre del concepto. Queda en null cuando la relacion no existe, y en ese caso el
        // bloque de los 4 casos especiales de mas abajo no se ejecuta.
        $concepto = null;

        if (is_null($concepto_movement)) {

            // Decision conservadora: sin concepto se trata el movimiento como uno comun y se
            // cae al calculo general (stock_resultante a partir del movimiento anterior), en
            // vez de asumir que es uno de los casos especiales que reinicia el stock_resultante
            // contra el stock actual del articulo.
            Log::warning('SetStockResultante: stock_movement sin concepto, se calcula el stock_resultante como movimiento comun. stock_movement_id: '.$stock_movement->id.' concepto_stock_movement_id: '.$stock_movement->concepto_stock_movement_id);

        } else {

            $concepto = $concepto_movement->name;
        }

        $article->fresh();

        // Si el movimiento es porque se esta repartiendo el stock en depositos
        // Se pone de stock actual el mismo que el stock del articulo
        if (
            $concepto == 'Mov entre depositos'
            || $concepto == 'Mov manual entre depositos'
            || $concepto == 'Importacion de excel'
            || $concepto == 'Creacion de deposito'
            ) {
            
            $stock_movement->stock_resultante = $article->stock;
            $stock_movement->save();

            // Log::info('Se esta repartiendo stock, concepto: '.$concepto.' se puso stock_resultante con el stock actual de: '.$article->stock);

            Self::set_stock_actual_in_observations($stock_movement, $article);
            return;
        }

        if (!is_null($article)) {

            $stock_movement_anterior = StockMovement::where('article_id', $article->id)
                                                    ->orderBy('id', 'DESC')
                                                    ->where('id', '<', $stock_movement->id)
                                                    ->first();

            if (!is_null($stock_movement_anterior)) {

                $stock_resultante = (float)$stock_movement_anterior->stock_resultante + (float)$stock_movement->amount;

                $stock_movement->stock_resultante = $stock_resultante;

            } else {
                // Log::info('No se hay stock_movement_anterior de ')
                $stock_movement->stock_resultante = $stock_movement->amount;
            }

            $stock_movement->save();
        } else {
            $stock_movement->stock_resultante = $stock_movement->amount;
            $stock_movement->save();
        }

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