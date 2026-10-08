<?php

namespace App\Http\Controllers\Helpers\sale;

use App\Http\Controllers\Helpers\SaleHelper;
use App\Models\AcopioArticleDelivery;
use Illuminate\Support\Facades\DB;

class AcopioHelper {
	
	static function set_delivered_amount($sale, $articles) {

        $en_acopio = 0;

        $acopio_article_delivery = AcopioArticleDelivery::create([
        	'sale_id'	=> $sale->id,
        ]);

        foreach ($articles as $article) {

            $add_delivered_amount = (int)$article['add_delivered_amount'];

            if ($add_delivered_amount) {
            	$acopio_article_delivery->articles()->attach($article['id'], [
            		'amount'	=> $add_delivered_amount,
            	]);

            	/*
            		🔴 Con la clave `article_variant_id` (la SPA nueva la manda SIEMPRE, con 0 para el
            		renglón sin variante) lo entregado va SOLO a las filas de esa variante (misión
            		variantes-mismo-articulo-en-vender, 8/10/2026). Sin la clave (SPA vieja), lo de
            		siempre: `updateExistingPivot()` sobre todas las filas del artículo. Entregar no
            		mueve stock (el stock salió al vender), así que no hay variante que elegir ahí.
            	*/
            	if (array_key_exists('article_variant_id', $article)) {

            		Self::sumar_entregadas_a_la_variante($sale, $article['id'], $article['article_variant_id'], $add_delivered_amount);

            		continue;
            	}

            	$current_delivered_amount = $sale->articles->find($article['id'])->pivot->delivered_amount;
            	$new_delivered_amount = $current_delivered_amount + $add_delivered_amount;

            	$sale->articles()->updateExistingPivot($article['id'], [
            		'delivered_amount'	=> $new_delivered_amount,
            	]);
            }
           
        }	

        $sale->load('articles');

        Self::actualizar_estado_acopio($sale);

	}
	
	/**
	 * Suma unidades entregadas a las filas de un artículo + variante (null/0/'' = las filas sin
	 * variante). Con una sola fila (lo normal) va entera ahí; con varias (varios precios) se reparte
	 * en orden de id sin pasar lo pendiente de cada fila, y lo que sobre va a la última.
	 *
	 * @param  \App\Models\Sale  $sale
	 * @param  int               $article_id
	 * @param  mixed             $article_variant_id
	 * @param  float             $add_delivered_amount
	 * @return void
	 */
	static function sumar_entregadas_a_la_variante($sale, $article_id, $article_variant_id, $add_delivered_amount) {

		$filas = SaleHelper::filas_del_renglon($sale->id, $article_id, $article_variant_id);

		$resto = (float)$add_delivered_amount;

		$ultima = count($filas) - 1;

		foreach ($filas as $indice => $fila) {

			$entregadas = (float)$fila->delivered_amount;

			$pendientes = max(0, (float)$fila->amount - $entregadas);

			$va = $indice == $ultima ? $resto : min($resto, $pendientes);

			if ($va == 0) {
				continue;
			}

			$resto -= $va;

			DB::table('article_sale')
				->where('id', $fila->id)
				->update(['delivered_amount' => $entregadas + $va]);
		}
	}

	// static function set_delivered_amount($sale, $articles) {

    //     $en_acopio = 0;

    //     foreach ($articles as $article) {

    //         $delivered_amount = (int)$article['delivered_amount'];

    //         $sale->articles()->updateExistingPivot($article['id'], [
    //             'delivered_amount'  => $delivered_amount,
    //         ]);
    //     }	

    //     $sale->load('articles');

    //     Self::actualizar_estado_acopio($sale);

	// }

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