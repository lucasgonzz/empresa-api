<?php

namespace App\Http\Controllers\Helpers\category;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Helpers\caja\MovimientoCajaHelper;
use App\Models\Article;
use App\Models\CategoryPriceTypeRange;
use App\Models\MovimientoCaja;
use App\Models\PriceType;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class SetPriceTypesHelper {

    static function set_price_types($category, $user = null) {

        Log::info('set_price_types');
        
        if (UserHelper::hasExtencion('lista_de_precios_por_categoria', $user)) {

            if (count($category->price_types) == 0) {
                
                $price_types = PriceType::where('user_id', $category->user_id)
                                        ->orderBy('created_at', 'ASC')
                                        ->get();

                foreach ($price_types as $price_type) {
                    
                    $category->price_types()->attach($price_type->id);
                }
            }


        } else {
            // Log::info('No entro');
        }

    }

    static function set_rangos($category, $user = null) {

        if (is_null($user)) {
            $user = UserHelper::user();
        }

        if (UserHelper::hasExtencion('lista_de_precios_por_rango_de_cantidad_vendida', $user)) {

            $price_types = PriceType::where('user_id', $category->user_id)
                                    ->orderBy('created_at', 'ASC')
                                    ->get();

            foreach ($price_types as $price_type) {

                CategoryPriceTypeRange::create([
                    'category_id'   => $category->id,
                    'price_type_id' => $price_type->id,
                    'user_id'       => $user->id,
                ]);
            }
        }

    }
}