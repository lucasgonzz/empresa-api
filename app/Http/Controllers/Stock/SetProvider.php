<?php

namespace App\Http\Controllers\Stock;

use App\Models\ConceptoStockMovement;
use Illuminate\Support\Facades\Log;

class SetProvider  {

    static function set_provider($stock_movement, $article, $data) {

        if (
            !is_null($article) 
            && !is_null($stock_movement->provider)
            && !isset($data['not_save_provider'])
        ) {
            $article->provider_id = $stock_movement->provider_id;
            $article->save();
        }

        if (
            !is_null($article) 
            && !is_null($stock_movement->provider)
        ) {


            $pivot_data = [
                'provider_code' => $article->provider_code,
                'cost'          => $article->cost,
                'price'         => $article->final_price,
                'amount'        => $stock_movement->amount,
            ];

            $provider_id = $stock_movement->provider_id;

            $existe_relacion = $article->providers()
                                    ->where('provider_id', $provider_id)
                                    ->exists();

            if ($existe_relacion) {

                // ✅ Actualizar pivot existente
                $article->providers()->updateExistingPivot($provider_id, $pivot_data);
            } else {
                // ✅ Crear pivot nuevo
                $article->providers()->attach($provider_id, $pivot_data);
            }
        }

    }

 }