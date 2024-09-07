<?php

namespace App\Http\Controllers\Helpers\article;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\UserHelper;
use App\Models\PriceType;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class ArticlePricesHelper {

    static function aplicar_precios_segun_listas_de_precios($article, $cost, $user) {
        
        $price_types = PriceType::where('user_id', $user->id)
                                ->orderBy('position', 'ASC')
                                ->get();

        foreach ($price_types as $price_type) {

            $percentage = $price_type->percentage;

            $relation = $article->price_types()->find($price_type->id);

            if (!is_null($relation)) {

                if (!is_null($relation->pivot->percentage)) {

                    $percentage = $relation->pivot->percentage;

                }
            }

            $price = $cost + ($cost * (float)$percentage / 100);

            Log::info($article->name.' se va a aplicar: ');
            Log::info('% '.$percentage);
            Log::info('price '.$price);

            $final_price = Self::aplicar_iva($article, $price, $user);
            
            $article->price_types()->syncWithoutDetaching($price_type->id);

            $article->price_types()->updateExistingPivot($price_type->id, [
                'percentage'    => $percentage,
                'price'         => $price,
                'final_price'   => $final_price,
            ]);

        }
    }

    static function aplicar_iva($article, $price, $user) {

        $precio_con_iva = $price;

        if (!$user->iva_included && Self::hasIva($article)) {

            $importe_iva = $price * $article->iva->percentage / 100;

            $precio_con_iva += $importe_iva;
        
            Log::info('sumando el iva a '.$article->name);
            Log::info('price '.$price);
            Log::info('iva '.$article->iva->percentage);
            Log::info('importe_iva '.$importe_iva);
            Log::info('quedo en '.$precio_con_iva);
        }

        return $precio_con_iva;
    }

    static function hasIva($article) {
        return !is_null($article->iva) && $article->iva->percentage != '0' && $article->iva->percentage != 'Exento' && $article->iva->percentage != 'No Gravado'; 
    }
}