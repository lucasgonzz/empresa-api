<?php

namespace App\Http\Controllers\Helpers\import\article;

use App\Http\Controllers\CommonLaravel\Helpers\ImportHelper;
use App\Http\Controllers\Helpers\caja\MovimientoCajaHelper;
use App\Models\MovimientoCaja;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class IsArticleUpdated {

    static function check($articulo_existente, $data, $global_updated_props) {

        $props_to_check = [
            'name',
            'bar_code',
            'provider_code',
            'stock_min',
            'iva_id',
            'cost_in_dollars',
            'percentage_gain',
            'category_id',
            'sub_category_id',
            'percentage_gain_blanco',
            'unidades_individuales',
            'presentacion',
            'bodega_id',
        ];

        $is_data_updated = false;


        $updated_props = [];




        foreach ($props_to_check as $prop) {
        
            if (
                isset($data[$prop]) 
                && $data[$prop] != $articulo_existente->{$prop}
            ) {
                Log::info('Hubo cambios en '.$prop);
                $updated_props[] = $prop;
            }
        }

        


        $epsilon = 0.00001;
        
        // Cheque si cambio el costo
        $new_cost = null;
        if (isset($data['cost'])) {
            $new_cost = (float)$data['cost'];
            Log::info('Esta seteado el cost');
        }
        $actual_cost = (float)$articulo_existente->cost;
        
        if (
            !is_null($new_cost) 
            && abs($actual_cost - $new_cost) > $epsilon) {
            
            // Log::info('Cambio el cost');
            $updated_props[] = 'cost';
        } 


        // Cheque si cambio el precio
        $new_price = null;
        if (isset($data['price'])) {
            $new_price = (float)$data['price'];
        }
        $actual_price = (float)$articulo_existente->price;

        if (
            !is_null($new_price) 
            && abs($actual_price - $new_price) > $epsilon) {
            
            $updated_props[] = 'price';
        }  


        

        if (count($updated_props) > 0) {
            $global_updated_props[$articulo_existente->id] = $updated_props;
            $is_data_updated = true;
        }

        return [
            'is_data_updated'   => $is_data_updated,
            'updated_props'     => $global_updated_props,
        ];

    }

	
}