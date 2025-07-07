<?php

namespace App\Http\Controllers\Helpers\article;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Models\ArticleDiscount;
use App\Models\ArticleDiscountBlanco;
use App\Models\ArticleSurchage;
use App\Models\ArticleSurchageBlanco;
use App\Models\PriceType;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class ArticlePricesHelper {

    // Extencion de golo norte
    static function aplicar_precios_segun_listas_de_precios_y_categorias($article, $cost, $user) {

        $price_types = null;

        $sub_category = $article->sub_category;
        $category = $article->category;

        // Priorizar los tipos de precios de la subcategoría si existen y tienen porcentaje válido
        if (!is_null($sub_category)) {
            $price_types = $sub_category->price_types()
                ->whereNotNull('price_type_sub_category.percentage') // Asegurar que el porcentaje no sea nulo
                ->where('price_type_sub_category.percentage', '!=', '') // Asegurar que no sea un string vacío
                ->get();
        }

        // Si no hay tipos de precios válidos en la subcategoría, buscar en la categoría
        if (is_null($price_types) || $price_types->isEmpty()) {
            if (!is_null($category)) {
                $price_types = $category->price_types()
                    // ->withPivot('percentage')
                    ->get();
                
                // Log::info('price_types de '.$category->name);
                // Log::info($price_types);
            }
        }



        if (!is_null($price_types)) {

            Log::info('Va a usar price types de la categoria');

            // Recorrer cada tipo de precio para calcular el precio final
            foreach ($price_types as $price_type) {

                $percentage = $price_type->pivot->percentage; // Porcentaje de ganancia

                if ($percentage) {
                    
                    // Calcular el precio final
                    
                    $price = $cost + ($cost * $percentage / 100);

                    $final_price = Self::aplicar_iva($article, $price, $user);

                    $article->price_types()->syncWithoutDetaching($price_type->id);

                    $article->price_types()->updateExistingPivot($price_type->id, [
                        'percentage'    => $percentage,
                        'price'         => ArticleHelper::redondear($price, $user),
                        'final_price'   => ArticleHelper::redondear($price, $user),
                    ]);
                } else {

                    $article->price_types()->updateExistingPivot($price_type->id, [
                        'percentage'    => null,
                        'price'         => null,
                        'final_price'   => null,
                    ]);
                }

            }
        }
        
    }

    static function aplicar_precios_segun_listas_de_precios($article, $cost, $user, $price_types = null) {
        
        if (is_null($price_types)) {
            $price_types = PriceType::where('user_id', $user->id)
                                    ->orderBy('position', 'ASC')
                                    ->get();
        }
                                
        Log::info('aplicar_precios_segun_listas_de_precios, price_types: '.count($price_types));

        foreach ($price_types as $price_type) {

            $percentage = $price_type->percentage;

            $relation = $article->price_types()->find($price_type->id);

            $previus_final_price = null;

            if (!is_null($relation)) {

                if (!is_null($relation->pivot->percentage)) {

                    $percentage = $relation->pivot->percentage;

                }

                if ($previus_final_price != $relation->pivot->final_price) {
                    $previus_final_price = $relation->pivot->final_price;
                }

            } else {
                // Log::info('El articulo num '.$article->id.' NO tenia relacion con el price_type '.$price_type->name.'. Se usa porcentaje por defecto');
            }

            $price = $cost + ($cost * (float)$percentage / 100);

            // Log::info($article->name.' se va a aplicar: ');
            // Log::info('% '.$percentage);
            // Log::info('al costo '.$cost);
            // Log::info('price '.$price);

            // Log::info('article id: '.$article->id.' '.$price_type->name.' queda en '.$price);

            $final_price = Self::aplicar_iva($article, $price, $user);

            // Log::info('Mas el '.$article->iva->percentage.' de iva, final_price: '.$final_price);
            
            $article->price_types()->syncWithoutDetaching($price_type->id);

            $article->price_types()->updateExistingPivot($price_type->id, [
                'percentage'            => $percentage,
                'price'                 => $price,
                'final_price'           => $final_price,
                'previus_final_price'   => $previus_final_price,
            ]);

            // Log::info('Seteando price_type '.$price_type->name.' para article num: '.$article->id.' con percentage '.$percentage.'% y final_price de '.$final_price);

        }
    }

    static function aplicar_iva($article, $price, $user) {

        $precio_con_iva = $price;

        $article->load('iva');

        if (!$user->iva_included && Self::hasIva($article)) {

            Log::info('iva: '.$article->iva->percentage);

            $importe_iva = $price * $article->iva->percentage / 100;

            $precio_con_iva += $importe_iva;

        }

        return $precio_con_iva;
    }

    static function hasIva($article) {
        return !is_null($article->iva) && $article->iva->percentage != '0' && $article->iva->percentage != 'Exento' && $article->iva->percentage != 'No Gravado'; 
    }

    static function aplicar_descuentos($article, $final_price) {

        if (count($article->article_discounts) >= 1) {
            foreach ($article->article_discounts as $discount) {
                $final_price -= $final_price * $discount->percentage / 100;
            }
        }
        return $final_price;
    }

    static function aplicar_recargos($article, $final_price) {

        if (count($article->article_surchages) >= 1) {
            foreach ($article->article_surchages as $surchage) {
                $final_price += $final_price * $surchage->percentage / 100;
            }
        }
        return $final_price;
    }

    static function set_precios_en_blanco($article) {

        // Log::info('set_precios_en_blanco para '.$article->name);

        $cost = $article->cost;

        // Log::info('cost: '.$cost);

        $cost = Self::aplicar_descuentos_blanco($article, $cost);

        $cost = Self::aplicar_recargos_blanco($article, $cost);


        if (!is_null($article->percentage_gain_blanco)) {

            $cost += $cost * $article->percentage_gain_blanco / 100;
            // Log::info('Poniendo marguen del '.$article->percentage_gain_blanco.', quedo en '.$cost);
        }


        if (env('REDONDEAR_PRECIOS_EN_CENTAVOS', false)) {
            $cost = round($cost);
        }

        $article->final_price_blanco = $cost;

        return $article;
    }

    static function aplicar_descuentos_blanco($article, $cost) {

        foreach ($article->article_discounts_blanco as $discount) {
            
            $cost -= $cost * $discount->percentage / 100;
            // Log::info('descontando el '.$discount->percentage.', quedo en '.$cost);
        }

        return $cost;
    }

    static function aplicar_recargos_blanco($article, $cost) {

        foreach ($article->article_surchages_blanco as $surchage) {
            
            $cost += $cost * $surchage->percentage / 100;
            // Log::info('aumentando el '.$surchage->percentage.', quedo en '.$cost);
        }

        return $cost;
    }


    static function adjuntar_descuentos($article, $article_discounts) {
        
        // Borro los descuentos actuales para aplicar todos desde 0
        ArticleDiscount::where('article_id', $article->id)
                        ->delete();

        if ($article_discounts) {
            
            foreach ($article_discounts as $discount) {
                $discount = (object) $discount;
                if ($discount->percentage != '') {
                    ArticleDiscount::create([
                        'percentage' => $discount->percentage,
                        'article_id' => $article->id,
                    ]);
                    // Log::info('Se creo descuento de '.$discount->percentage);
                }
            }
        }
    }


    static function adjuntar_descuentos_en_blanco($article, $article_discounts) {
        
        // Borro los descuentos actuales para aplicar todos desde 0
        ArticleDiscountBlanco::where('article_id', $article->id)
                        ->delete();

        if ($article_discounts) {
            
            foreach ($article_discounts as $discount) {
                $discount = (object) $discount;
                if ($discount->percentage != '') {
                    ArticleDiscountBlanco::create([
                        'percentage' => $discount->percentage,
                        'article_id' => $article->id,
                    ]);
                    // Log::info('Se creo descuento en blanco de '.$discount->percentage.' para article_id: '.$article->id);
                }
            }
        }
    }


    static function adjuntar_recargos($article, $article_surchages) {
        
        // Borro los recargos actuales para aplicar todos desde 0
        ArticleSurchage::where('article_id', $article->id)
                        ->delete();

        if ($article_surchages) {
            
            foreach ($article_surchages as $surchage) {
                $surchage = (object) $surchage;
                if ($surchage->percentage != '') {
                    ArticleSurchage::create([
                        'percentage' => $surchage->percentage,
                        'article_id' => $article->id,
                    ]);
                    // Log::info('Se creo recargo de '.$surchage->percentage);
                }
            }
        }
    }


    static function adjuntar_recargos_en_blanco($article, $article_surchages) {
        
        // Borro los recargos actuales para aplicar todos desde 0
        ArticleSurchageBlanco::where('article_id', $article->id)
                        ->delete();

        if ($article_surchages) {
            
            foreach ($article_surchages as $surchage) {
                $surchage = (object) $surchage;
                if ($surchage->percentage != '') {
                    ArticleSurchageBlanco::create([
                        'percentage' => $surchage->percentage,
                        'article_id' => $article->id,
                    ]);
                    // Log::info('Se creo recargo en blanco de '.$surchage->percentage);
                }
            }
        }
    }
}