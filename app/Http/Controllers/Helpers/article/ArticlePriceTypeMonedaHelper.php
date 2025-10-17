<?php

namespace App\Http\Controllers\Helpers\article;

use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Helpers\article\ArticlePricesHelper;
use Illuminate\Support\Facades\Log;

class ArticlePriceTypeMonedaHelper {


    static function attach_price_type_monedas($article, $price_type_monedas, $user = null) {

        if (empty($price_type_monedas)) return;

        if (!$user) {
            $user = UserHelper::user();
        }

        $cost = $article->cost;

        $cost = ArticlePricesHelper::aplicar_iva($article, $cost, $user);

        // price_type_monedas: array de {price_type_id, moneda_id, pivot: {percentage, final_price, setear_precio_final, incluir_en_excel}}
        
        foreach ($price_type_monedas as $ptm) {

            $moneda_id = $ptm['moneda_id'];
            
            $setear_precio_final = 0;

            if (
                isset($ptm['setear_precio_final'])
                && (
                    $ptm['setear_precio_final'] == 1
                    || $ptm['setear_precio_final'] == '1'
                )
            ) {

                $setear_precio_final = 1;
            }

            $percentage = (float)$ptm['percentage'];
            $final_price = (float)$ptm['final_price'];

            // Log::info('moneda_id: '.$moneda_id);
            // Log::info('percentage: '.$percentage);
            // Log::info('final_price: '.$final_price);

            if ($setear_precio_final) {

                $percentage = ($final_price - $cost) / $cost * 100;

            } else {

                $final_price = $cost + ($cost * (float)$percentage / 100);

            }

            if (
                $article->cost_in_dollars
                && $setear_precio_final == 0
            ) {
                if ($moneda_id == 1) {
                    $final_price *= $user->dollar;
                }
            }

            $article->price_type_monedas()->updateOrCreate(
                [
                    'price_type_id' => $ptm['price_type_id'],
                    'moneda_id'     => $moneda_id,
                ],
                [
                    'percentage'                     => $percentage,
                    'final_price'                    => $final_price,
                    'setear_precio_final'            => $setear_precio_final,
                ]
            );
        }
    }
        

    public static function aplicar_precios_por_price_type_y_moneda($article, $user)
    {
        $entries = $article->price_type_monedas;

        if ($entries->isEmpty()) {
            return;
        }

        $usd_id = 2;
        $ars_id = 1;

        $rate = (!is_null($article->provider) && !is_null($article->provider->dolar) && (float)$article->provider->dolar > 0)
                ? (float)$article->provider->dolar
                : (float)$user->dollar;

        $groups = $entries->groupBy('price_type_id');

        foreach ($groups as $price_type_id => $group) {

            $usd_entry = $usd_id ? $group->firstWhere('moneda_id', $usd_id) : null;
            $ars_entry = $ars_id ? $group->firstWhere('moneda_id', $ars_id) : null;

            $calc_from_cost = function ($base_cost, $entry, $aplicar_iva) use ($article, $user) {
                $final_price = null;
                $percentage  = null;

                if ($entry->setear_precio_final && $entry->final_price !== null && $entry->final_price !== '') {
                    $final_price = (float)$entry->final_price;

                    $costo_base = $aplicar_iva
                        ? ArticlePricesHelper::aplicar_iva($article, $base_cost, $user)
                        : $base_cost;

                    $percentage = $costo_base > 0
                        ? ($final_price - $costo_base) / $costo_base * 100
                        : 0;

                } else {
                    $percentage = $entry->percentage !== null && $entry->percentage !== '' ? (float)$entry->percentage : 0;
                    $price_wo_iva = $base_cost + ($base_cost * $percentage / 100);
                    $final_price  = $aplicar_iva
                        ? ArticlePricesHelper::aplicar_iva($article, $price_wo_iva, $user)
                        : $price_wo_iva;
                }

                return [$percentage, $final_price];
            };

            $save_entry = function ($entry, $percentage, $final_price) {
                $entry->percentage  = $percentage;
                $entry->final_price = $final_price;
                $entry->save();
            };

            if ($article->cost_in_dollars) {
                $cost_usd = (float)$article->cost;

                // USD → ❌ sin IVA
                if ($usd_entry) {
                    [$usd_percentage, $usd_final] = $calc_from_cost($cost_usd, $usd_entry, false);
                    $save_entry($usd_entry, $usd_percentage, $usd_final);
                }

                // ARS → ✅ con IVA
                if ($ars_entry) {
                    if (isset($usd_final)) {
                        $precio_sin_iva_ars = $usd_final * $rate;
                        $precio_con_iva_ars = ArticlePricesHelper::aplicar_iva($article, $precio_sin_iva_ars, $user);

                        $cost_ars = $cost_usd * $rate;
                        $costo_con_iva_ars = ArticlePricesHelper::aplicar_iva($article, $cost_ars, $user);

                        $ars_percentage = $costo_con_iva_ars > 0
                            ? (($precio_con_iva_ars - $costo_con_iva_ars) / $costo_con_iva_ars * 100)
                            : 0;

                        $save_entry($ars_entry, $ars_percentage, $precio_con_iva_ars);
                    } else {
                        $cost_ars = $cost_usd * $rate;
                        [$ars_percentage, $ars_final] = $calc_from_cost($cost_ars, $ars_entry, true);
                        $save_entry($ars_entry, $ars_percentage, $ars_final);
                    }
                }
            } else {
                $cost_ars = (float)$article->cost;

                // ARS → ✅ con IVA
                if ($ars_entry) {
                    [$ars_percentage, $ars_final] = $calc_from_cost($cost_ars, $ars_entry, true);
                    $save_entry($ars_entry, $ars_percentage, $ars_final);
                }

                // USD → ❌ sin IVA
                if ($usd_entry && isset($ars_final) && $rate > 0) {
                    $usd_final = $ars_final / $rate;
                    $usd_percentage = $usd_entry->percentage;
                    $save_entry($usd_entry, $usd_percentage, $usd_final);
                }

                // Si no hay ARS pero sí USD
                if (!$ars_entry && $usd_entry && $rate > 0) {
                    $cost_usd = $cost_ars / $rate;
                    [$usd_percentage, $usd_final] = $calc_from_cost($cost_usd, $usd_entry, false);
                    $save_entry($usd_entry, $usd_percentage, $usd_final);
                }
            }
        }
    }



    public static function VIEJO_aplicar_precios_por_price_type_y_moneda($article, $user)
    {
        // Traemos las relaciones con la moneda para identificar USD/ARS sin "id mágicos"
        $entries = $article->price_type_monedas;

        if ($entries->isEmpty()) {
            return;
        }

        // Resolver IDs de USD y ARS por código o nombre (ajustá si tu tabla Moneda usa otros campos)
        // Asumimos columnas: monedas.code ('USD'/'ARS') o name ('Dólar','Peso', etc.)
        $usd_id = 2;

        $ars_id = 1;

        // Cotización
        $rate = (!is_null($article->provider) && !is_null($article->provider->dolar) && (float)$article->provider->dolar > 0)
                ? (float)$article->provider->dolar
                : (float)$user->dollar;

        // Agrupamos por price_type_id
        $groups = $entries->groupBy('price_type_id');

        foreach ($groups as $price_type_id => $group) {

            // Buscamos las dos monedas si existen
            $usd_entry = $usd_id ? $group->firstWhere('moneda_id', $usd_id) : null;
            $ars_entry = $ars_id ? $group->firstWhere('moneda_id', $ars_id) : null;

            // Helpers internos
            $calc_from_cost = function ($base_cost, $entry) use ($article, $user) {
                // Columns: percentage, final_price, setear_precio_final
                $final_price = null;
                $percentage  = null;

                if ($entry->setear_precio_final && $entry->final_price !== null && $entry->final_price !== '') {
                    // Usuario fijó precio final → recalculamos percentage
                    $final_price = (float)$entry->final_price;
                    // Costo con IVA para que el % sea consistente con tus otras rutinas
                    $costo_con_iva = ArticlePricesHelper::aplicar_iva($article, $base_cost, $user);
                    if ($costo_con_iva > 0) {
                        $percentage = ($final_price - $costo_con_iva) / $costo_con_iva * 100;
                    } else {
                        $percentage = 0;
                    }
                } else {
                    // Usuario indicó porcentaje → calculamos final
                    $percentage = $entry->percentage !== null && $entry->percentage !== '' ? (float)$entry->percentage : 0;
                    $price_wo_iva = $base_cost + ($base_cost * $percentage / 100);
                    $final_price  = ArticlePricesHelper::aplicar_iva($article, $price_wo_iva, $user);
                }

                return [$percentage, $final_price];
            };

            $save_entry = function ($entry, $percentage, $final_price) {
                $entry->percentage  = $percentage;
                $entry->final_price = $final_price; // <- cambiar por $entry->precio_final si tu columna se llama así
                $entry->save();
            };

            if ($article->cost_in_dollars) {
                // El costo viene en USD

                // 1) USD: aplicar margen o setear final (sobre costo USD)
                if ($usd_entry) {
                    $cost_usd = (float)$article->cost;
                    [$usd_percentage, $usd_final] = $calc_from_cost($cost_usd, $usd_entry);
                    $save_entry($usd_entry, $usd_percentage, $usd_final);

                    // 2) ARS: cotizar desde el final USD → ARS
                    if ($ars_entry && $usd_final !== null) {
                        $ars_final = $usd_final * $rate;
                        // En la consigna pedís “simplemente cotizar”, sin recalcular %,
                        // pero si querés podés recalcular % con respecto al costo ARS con IVA:
                        // $cost_ars = $cost_usd * $rate;
                        // $costo_con_iva_ars = ArticlePricesHelper::aplicar_iva($article, $cost_ars, $user);
                        // $ars_percentage = $costo_con_iva_ars > 0 ? (($ars_final - $costo_con_iva_ars)/$costo_con_iva_ars*100) : 0;
                        $ars_percentage = $ars_entry->percentage; // mantenemos lo que tenga o null
                        $save_entry($ars_entry, $ars_percentage, $ars_final);
                    }
                } else {
                    // Si no hay USD pero sí ARS, calculamos “normal” sobre ARS partiendo de costo*rate
                    if ($ars_entry) {
                        $cost_ars = (float)$article->cost * $rate;
                        [$ars_percentage, $ars_final] = $calc_from_cost($cost_ars, $ars_entry);
                        $save_entry($ars_entry, $ars_percentage, $ars_final);
                    }
                }

            } else {
                // El costo viene en ARS

                // 1) ARS: aplicar margen o setear final (sobre costo ARS)
                if ($ars_entry) {
                    $cost_ars = (float)$article->cost;
                    [$ars_percentage, $ars_final] = $calc_from_cost($cost_ars, $ars_entry);
                    $save_entry($ars_entry, $ars_percentage, $ars_final);

                    // 2) USD: convertir desde final ARS → USD
                    if ($usd_entry && $ars_final !== null && $rate > 0) {
                        $usd_final = $ars_final / $rate;
                        $usd_percentage = $usd_entry->percentage; // mantenemos lo que tenga o null
                        $save_entry($usd_entry, $usd_percentage, $usd_final);
                    }
                } else {
                    // Si no hay ARS pero sí USD, calculamos “normal” sobre USD partiendo de costo/rate
                    if ($usd_entry && $rate > 0) {
                        $cost_usd = (float)$article->cost / $rate;
                        [$usd_percentage, $usd_final] = $calc_from_cost($cost_usd, $usd_entry);
                        $save_entry($usd_entry, $usd_percentage, $usd_final);
                    }
                }
            }

        }
    }



    // static function aplicar_precios_por_price_type_y_moneda($article, $user) {

    //     $entries = $article->price_type_monedas; // colección pivot como PivotModel

    //     foreach ($entries as $entry) {

    //         $cost = $article->cost;

    //         if (
    //             $article->cost_in_dollars
    //             && $entry->moneda_id == 1
    //         ) {
    //             $rate = (!is_null($article->provider) && $article->provider->dolar)
    //                   ? $article->provider->dolar
    //                   : $user->dollar;
    //             $cost = $cost * $rate;
    //         }

    //         $final_price = null;

    //         if ($entry->setear_precio_final && !is_null($entry->precio_final)) {
    //             $final_price = $entry->precio_final;
    //             $percentage = ($final_price - $cost) / $cost * 100;
    //         } elseif (!is_null($entry->percentage)) {
    //             $percentage = $entry->percentage;
    //             $price_wo_iva = $cost + ($cost * $percentage / 100);
    //             $final_price = ArticlePricesHelper::aplicar_iva($article, $price_wo_iva, $user);
    //         } else {
    //             continue;
    //         }

    //         $entry->percentage = $percentage;
    //         $entry->final_price = $final_price;
    //         $entry->save();
    //     }
    // }

}