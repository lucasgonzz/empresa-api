<?php

namespace App\Http\Controllers\Stock;
use Illuminate\Support\Facades\Log;

class SetStockUpdatedAt  {

    /**
     * Actualiza la fecha de ultimo movimiento de stock del articulo, salvo que el
     * movimiento sea una venta.
     *
     * @param  \App\Models\StockMovement  $stock_movement  Movimiento de stock recien guardado.
     * @param  \App\Models\Article        $article         Articulo afectado por el movimiento.
     * @return void
     */
    static function set_stock_updated_at($stock_movement, $article) {

        // Relacion con el concepto del movimiento. Puede venir null cuando el concepto no se
        // pudo resolver por nombre (ver SetConcepto).
        $concepto_movement = $stock_movement->concepto_movement;

        if (is_null($concepto_movement)) {

            // Decision conservadora: sin concepto se trata como si fuera una venta, o sea NO se
            // toca stock_updated_at. Pisar esa fecha sin necesidad corrompe los reportes de
            // rotacion de stock; no tocarla en un caso dudoso no rompe nada.
            Log::warning('SetStockUpdatedAt: stock_movement sin concepto, no se actualiza stock_updated_at. stock_movement_id: '.$stock_movement->id.' concepto_stock_movement_id: '.$stock_movement->concepto_stock_movement_id);
            return;
        }

        if (
            strpos($concepto_movement->name, 'Venta') === false
            && strpos($concepto_movement->name, 'venta') === false
        ) {
            $article->stock_updated_at = $stock_movement->created_at;
            $article->timestamps = false;
            $article->save();
            // Log::info('SI se seteo stock_updated_at');
        } else {
            // Log::info('No se seteo stock_updated_at');
        }

    }

 }