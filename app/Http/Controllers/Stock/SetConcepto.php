<?php

namespace App\Http\Controllers\Stock;

use App\Models\ConceptoStockMovement;
use Illuminate\Support\Facades\Log;

class SetConcepto  {

    static function get_concepto($data) {

        $concepto_id = null;

        if (
            isset($data['concepto_stock_movement_id'])
            && !is_null($data['concepto_stock_movement_id'])
        ) {

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
            } else {

                // La busqueda por nombre no encontro nada: el movimiento va a quedar sin
                // concepto. No se pone un id de fallback a proposito, porque etiquetar el
                // movimiento con un concepto ajeno corrompe el dato en silencio. Se deja
                // registrado en el log para poder detectarlo.
                Log::warning('SetConcepto::get_concepto: no se encontro el concepto de nombre "'.$data['concepto_stock_movement_name'].'". El stock movement va a quedar SIN concepto (concepto_stock_movement_id null).');
            }

        } else {

            $concepto_id = 1;
        }

        return $concepto_id;
    }

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
            } else {

                // Mismo criterio que en get_concepto(): sin fallback de id, solo se deja
                // constancia en el log de que la busqueda por nombre fallo.
                Log::warning('SetConcepto::set_concepto: no se encontro el concepto de nombre "'.$data['concepto_stock_movement_name'].'". El stock movement va a quedar SIN concepto (concepto_stock_movement_id null).');
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