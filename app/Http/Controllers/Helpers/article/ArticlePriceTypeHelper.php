<?php

namespace App\Http\Controllers\Helpers\article;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\UserHelper;
use App\Models\PriceType;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class ArticlePriceTypeHelper {

    static function attach_price_types($article, $price_types) {
        
        foreach ($price_types as $price_type) {

            $article->price_types()->syncWithoutDetaching($price_type['id']);

            $price_type_model = null;

            $final_price = null;

            if (isset($price_type['pivot']['percentage'])) {

                $percentage = $price_type['pivot']['percentage'];
            } else {

                $price_type_model = Self::get_price_type($price_type['id']);

                $percentage = $price_type_model->percentage;
            }

            if (isset($price_type['pivot']['final_price'])) {
                $final_price = $price_type['pivot']['final_price'];
            }


            $incluir_en_excel_para_clientes = Self::get_incluir_en_excel_para_clientes($price_type, $price_type_model);
            $setear_precio_final = Self::get_setear_precio_final($price_type, $price_type_model);


            $article->price_types()->updateExistingPivot($price_type['id'], [
                'percentage'                        => $percentage,
                'final_price'                       => $final_price,
                'incluir_en_excel_para_clientes'    => $incluir_en_excel_para_clientes,
                'setear_precio_final'               => $setear_precio_final,
            ]);
        }
    }

    static function get_setear_precio_final($price_type, $price_type_model) {
        
        if (isset($price_type['pivot']['setear_precio_final'])) {

            return $price_type['pivot']['setear_precio_final'];
        }

        if (is_null($price_type_model)) {
            $price_type_model = Self::get_price_type($price_type['id']);
        }

        return $price_type_model['setear_precio_final'];
    }

    static function get_incluir_en_excel_para_clientes($price_type, $price_type_model) {
        
        if (isset($price_type['pivot']['incluir_en_excel_para_clientes'])) {

            return $price_type['pivot']['incluir_en_excel_para_clientes'];
        }

        if (is_null($price_type_model)) {
            $price_type_model = Self::get_price_type($price_type['id']);
        }

        return $price_type_model['incluir_en_lista_de_precios_de_excel'];
    }

    static function get_price_type($id) {
        return PriceType::find($id);
    }
}