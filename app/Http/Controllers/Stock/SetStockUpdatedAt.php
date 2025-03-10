<?php

namespace App\Http\Controllers\Stock;
use Illuminate\Support\Facades\Log;

class SetStockUpdatedAt  {

    static function set_stock_updated_at($stock_movement, $article) {

        if (
            !str_contains($stock_movement->concepto->name, 'Venta')
            && !str_contains($stock_movement->concepto->name, 'venta')
        ) {
            $article->stock_updated_at = $stock_movement->created_at;
            $article->timestamps = false;
            $article->save();
            Log::info('SI se seteo stock_updated_at');
        } else {
            Log::info('No se seteo stock_updated_at');
        }

    }

 }