<?php

namespace App\Http\Controllers\Stock;

use App\Models\ConceptoStockMovement;
use App\Models\StockMovement;
use Illuminate\Support\Facades\Log;

class SetStockResultante  {

    static function set_stock_resultante($stock_movement, $article) {

        $concepto = $stock_movement->concepto_movement->name;

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