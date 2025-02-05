<?php

namespace App\Http\Controllers\Stock;

use App\Models\ConceptoStockMovement;
use Illuminate\Support\Facades\Log;

class SetConcepto  {

    static function set_concepto($stock_movement, $data) {

        $concepto_id = null;

        if (
            isset($data['concepto_stock_movement_id'])
            && !is_null($data['concepto_stock_movement_id'])
        ) {

            Log::info('viene concepto_stock_movement_id: '.$data['concepto_stock_movement_id']);

            $concepto_id = $data['concepto_stock_movement_id'];
        
        } else if (
            isset($data['concepto_stock_movement_name'])
            && !is_null($data['concepto_stock_movement_name'])
        ) {

            Log::info('viene concepto_stock_movement_name: '.$data['concepto_stock_movement_name']);

            $concepto = ConceptoStockMovement::where('name', $data['concepto_stock_movement_name'])
                                                ->first();

            if ($concepto) {
                Log::info('se encontro');
                $concepto_id = $concepto->id;
            }

        } else {
            
            $concepto_id = 1;
        }

        Log::info('concepto_id: '.$concepto_id);

        $stock_movement->concepto_stock_movement_id = $concepto_id;
        $stock_movement->save();

        return $stock_movement;

    }

 }