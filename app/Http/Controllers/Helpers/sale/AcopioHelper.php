<?php

namespace App\Http\Controllers\Helpers\sale;

class AcopioHelper {
	
	static function set_delivered_amount($sale, $articles) {

        $en_acopio = 0;

        foreach ($articles as $article) {

            $delivered_amount = (int)$article['delivered_amount'];

            $sale->articles()->updateExistingPivot($article['id'], [
                'delivered_amount'  => $delivered_amount,
            ]);
        }	

        $sale->load('articles');

        Self::actualizar_estado_acopio($sale);

	}

	static function actualizar_estado_acopio($sale) {

	    $todos_entregados = true;
	    $al_menos_uno_entregado = false;

	    foreach ($sale->articles as $article) {
	        $vendidas = $article->pivot->amount;
	        $entregadas = $article->pivot->delivered_amount ?? 0;

	        if ($entregadas > 0) {
	            $al_menos_uno_entregado = true;
	        }

	        if ($entregadas < $vendidas) {
	            $todos_entregados = false;
	        }
	    }

	    $nuevo_estado = false;

	    if ($al_menos_uno_entregado && !$todos_entregados) {
	        $nuevo_estado = true;
	    }

	    if ($sale->en_acopio !== $nuevo_estado) {
	        $sale->en_acopio = $nuevo_estado;
	        $sale->save();
	    }
	}

}