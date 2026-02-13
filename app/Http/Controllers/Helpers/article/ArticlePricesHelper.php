<?php

namespace App\Http\Controllers\Helpers\article;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\Numbers;
use App\Http\Controllers\Helpers\UserHelper;
use App\Models\ArticleDiscount;
use App\Models\ArticleDiscountBlanco;
use App\Models\ArticleSurchage;
use App\Models\ArticleSurchageBlanco;
use App\Models\PriceType;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class ArticlePricesHelper {

    static function aplicar_category_percentage_gain($article, $price, $des) {

        if (
            $article->category
            && $article->category->percentage_gain
        ) {
            // Log::info('La categoria tiene margen de ganancia del '.$article->category->percentage_gain);
            // Log::info('price: '.$price);
            $price += $price * $article->category->percentage_gain / 100;
            $des[] = 'Mas margen de la categoria del '.$article->category->percentage_gain.'% = '.Numbers::price($price, true);
            // Log::info('price luego: '.$price);
        } else {
            // Log::info('La categoria NO tiene margen de ganancia');
        }
        return [
            'price' => $price,
            'des'   => $des,
        ];
    }


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
                                
        // Log::info('aplicar_precios_segun_listas_de_precios, price_types: '.count($price_types));

        foreach ($price_types as $price_type) {

            $percentage = $price_type->percentage;

            $final_price = null;

            $relation = $article->price_types()->find($price_type->id);

            $previus_final_price = null;


            /* 
                Calculo el precio final, o el porcentaje, en base a la relacion ya existente
            */
            if (!is_null($relation)) {

                if ($relation->pivot->setear_precio_final) {

                    if (!is_null($relation->pivot->final_price)) {

                        $final_price = $relation->pivot->final_price;
                    }

                } else {

                    if (!is_null($relation->pivot->percentage)) {

                        $percentage = $relation->pivot->percentage;
                    }

                    if ($previus_final_price != $relation->pivot->final_price) {
                        $previus_final_price = $relation->pivot->final_price;
                    }
                }
            }



            /*
                Si esta seteado el precio final, calculo el procentaje que deberia de tener para 
                llegar a ese precio final

                Sino, calculo el precio final en base al porcentaje
            */

            $cost = (float) $cost;

            // Normalizo porcentaje por si viene null/string
            $percentage = is_null($percentage) ? 0 : (float) $percentage;

            if ($cost !== 0.0) {
                
                if (!is_null($final_price)) {


                    $percentage = ($final_price - $cost) / $cost * 100;

                } else {

                    $final_price = $cost + ($cost * (float)$percentage / 100);

                }
            } 


            $res = Self::aplicar_price_type_surchages($price_type, $final_price, $cost);



            $article->price_types()->syncWithoutDetaching($price_type->id);

            $article->price_types()->updateExistingPivot($price_type->id, [
                'percentage'            => $percentage,
                // 'price'                 => $price,
                'final_price'           => $final_price,
                'previus_final_price'   => $previus_final_price,
                'precio_luego_de_recargos'  => $res['precio_luego_de_recargos'],
                'monto_ganancia'  => $res['monto_ganancia'],
            ]);

            Log::info('Seteando price_type '.$price_type->name.' para article num: '.$article->id.' con percentage '.$percentage.'% y final_price de '.$final_price);

        }
    }

    static function aplicar_price_type_surchages($price_type, $final_price, $cost) {

        $precio_luego_de_recargos = $final_price;

        foreach ($price_type->price_type_surchages as $price_type_surchage) {
            
            if (!is_null($price_type_surchage->percentage)) {

                $precio_luego_de_recargos -= $precio_luego_de_recargos * $price_type_surchage->percentage / 100;
            
            } else if (!is_null($price_type_surchage->amount)) {

                $precio_luego_de_recargos -= $price_type_surchage->amount;

            }
        }

        return [
            'precio_luego_de_recargos'  => $precio_luego_de_recargos,
            'monto_ganancia'            => $precio_luego_de_recargos - $cost,
        ];


    }

    static function aplicar_iva($article, $price, $user, $des = []) {

        $precio_con_iva = $price;

        if ($article->aplicar_iva) {
            
            $article->load('iva');

            if (!$user->iva_included && Self::hasIva($article)) {

                // Log::info('iva: '.$article->iva->percentage);

                $importe_iva = $price * $article->iva->percentage / 100;

                $precio_con_iva += $importe_iva;

                $des[] = 'Mas IVA de '.$article->iva->percentage.'% = '.Numbers::price($precio_con_iva, true);
            }
        }

        return [
            'price'   => $precio_con_iva,
            'des'     => $des,
        ];
    }

    static function hasIva($article) {
        return !is_null($article->iva) && $article->iva->percentage != '0' && $article->iva->percentage != 'Exento' && $article->iva->percentage != 'No Gravado'; 
    }

    static function aplicar_descuentos($article, $price, $des = []) {

        if (count($article->article_discounts) >= 1) {
            foreach ($article->article_discounts as $discount) {

                if (!is_null($discount->percentage)) {
                    $price -= $price * $discount->percentage / 100;
                    $des[] = 'Menos descuento de '.$discount->percentage.'% = '.Numbers::price($price, true);
                } else if (!is_null($discount->amount)) {
                    $price -= $discount->amount;
                    $des[] = 'Menos descuento de $'.$discount->amount.' = '.Numbers::price($price, true);
                }

            }
        }
        return [
            'price'   => $price,
            'des'     => $des,
        ];
    }

    static function aplicar_provider_discounts($article, $price, $des) {

        if (!is_null($article->provider)) {

            foreach ($article->provider->provider_discounts as $discount) {
                $price -= $price * $discount->percentage / 100;
                $des[] = 'Menos descuento del proveedor de '.$discount->percentage.'% = '.Numbers::price($price, true);
            }
        }
        return [
            'price'   => $price,
            'des'     => $des,
        ];
    }

    static function aplicar_recargos($article, $price, $luego_del_precio_final = false, $des = []) {

        if (count($article->article_surchages) >= 1) {

            foreach ($article->article_surchages as $surchage) {

                if (
                    (
                        $surchage->luego_del_precio_final == 0
                        && !$luego_del_precio_final
                    )
                    || 
                    (
                        $surchage->luego_del_precio_final == 1
                        && $luego_del_precio_final
                    )
                ) {
                    // Log::info('Aplicando recargo luego_del_precio_final: '.$surchage->luego_del_precio_final);
                    // Log::info('luego_del_precio_final param: '.$luego_del_precio_final);
                    if (!is_null($surchage->percentage)) {
                        $price += $price * $surchage->percentage / 100;
                        $des[] = 'Mas recargo de '.$surchage->percentage.'% = '.Numbers::price($price, true);
                    } else if (!is_null($surchage->amount)) {
                        $price += $surchage->amount;
                        $des[] = 'Mas recargo de $'.$surchage->percentage.' = '.Numbers::price($price, true);
                    }
                } 


            }
        }
        return [
            'price'   => $price,
            'des'     => $des,
        ];
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