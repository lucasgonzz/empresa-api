<?php

namespace App\Http\Controllers\Helpers;
use App\Models\SaleModification;
use Illuminate\Support\Facades\Log;

class SaleModificationsHelper {

    static function create($sale, $instance) {
        return SaleModification::create([
            'sale_id'                       => $sale->id,
            'estado_antes_de_actualizar'    => Self::get_estado($sale),
            'user_id'                       => $instance->userId(false),
        ]);
    }

    static function get_estado($sale) {
    	$estado = 'ninguno';
    	if ($sale->to_check) {
    		$estado = 'Para chequear';
    	} else if ($sale->checked) {
    		$estado = 'Chequeada';
    	} else if ($sale->confirmed) {
    		$estado = 'Confirmada';
    	}
    	return $estado;
    }

    static function attach_articulos_despues_de_actualizar($sale, $sale_modification) {
        
        if (!is_null($sale_modification)) {
            $sale->load('articles');
            foreach ($sale->articles as $article) {
                Log::info($article->name);
                Log::info('amount '.$article->pivot->amount);
                Log::info('checked_amount '.$article->pivot->checked_amount);
                $sale_modification->articulos_despues_de_actualizar()->attach($article->id, [
                    'amount'            => $article->pivot->amount,
                    'checked_amount'    => $article->pivot->checked_amount,
                ]);
            }
        }
    }

    static function attach_articulos_antes_de_actualizar($sale, $sale_modification) {

        if (!is_null($sale_modification)) {
            $sale->load('articles');
            foreach ($sale->articles as $article) {
                Log::info($article->name);
                Log::info('amount '.$article->pivot->amount);
                Log::info('checked_amount '.$article->pivot->checked_amount);
                $sale_modification->articulos_antes_de_actualizar()->attach($article->id, [
                    'amount'            => $article->pivot->amount,
                    'checked_amount'    => $article->pivot->checked_amount,
                ]);
            }
        }
    }

    static function get_amount($article) {
        if (!is_null($article->pivot->checked_amount)) {
            return $article->pivot->checked_amount;
        }
        return $article->pivot->amount;
    }

}