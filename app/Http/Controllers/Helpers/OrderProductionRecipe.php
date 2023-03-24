<?php

namespace App\Http\Controllers\Helpers;
use Illuminate\Support\Facades\Log;

class OrderProductionRecipe {

	static function getCantidadesActuales($order_production) {
		$cantidades_actuales = [];
		foreach ($order_production->articles_finished as $article_finished) {
			$cantidades_actuales[$article_finished->id][$article_finished->pivot->order_production_status_id] = $article_finished->pivot->amount;
		}
		Log::info('cantidades_actuales:');
		Log::info($cantidades_actuales);
		return $cantidades_actuales;
	}

	static function checkRecipes($order_production, $cantidades_actuales) {
		foreach ($order_production->articles_finished as $article_finished) {
			Log::info('Checkeando '.$article_finished->name.' en el paso '.$article_finished->pivot->order_production_status_id);
			Self::checkRecipe($article_finished, $cantidades_actuales);
		}
	}

	static function checkRecipe($article_finished, $cantidades_actuales) {
		if (!is_null($article_finished->recipe)) {
			foreach ($article_finished->recipe->articles as $article_recipe) {
				if ($article_recipe->pivot->order_production_status_id == $article_finished->pivot->order_production_status_id) {
					Log::info('Entro con '.$article_recipe->name);
					Log::info('Necesita '.$article_recipe->pivot->amount.' de '.$article_recipe->name);
					Self::discountStock($article_recipe, $article_finished, $cantidades_actuales);
				}
			}
		}
	}

	static function discountStock($article_recipe, $article_finished, $cantidades_actuales) {
		// $cantidad_a_descontar = $article_recipe->pivot->amount * $article_finished->pivot->amount;
		$previus_amount = null;
		if (isset($cantidades_actuales[$article_finished->id])) {
			$previus_amount = $cantidades_actuales[$article_finished->id][$article_finished->pivot->order_production_status_id];
		}
		if (is_null($previus_amount)) {
			$previus_amount = 0;
		}
		Log::info('Cantidad anterior: '.$previus_amount);
		Log::info('Cantidad actual: '.$article_finished->pivot->amount);
		$diferencia = $article_finished->pivot->amount - $previus_amount;
		Log::info('Diferencia: '.$diferencia);
		$cantidad_a_descontar = $article_recipe->pivot->amount * $diferencia;
		Log::info('Cantidad de '.$article_recipe->name.' a descontar: '.$article_recipe->pivot->amount.' * '.$diferencia.' = '.$cantidad_a_descontar);
		// $diferencia_cantidad_a_descontar = $cantidad_a_descontar - $previus_amount;
		// Log::info('Cantidad a descontar: '.$cantidad_a_descontar);
		Log::info('Stock de '.$article_recipe->name.': '.$article_recipe->stock);
		$article_recipe->stock -= $cantidad_a_descontar;
		$article_recipe->save();
		Log::info('Nuevo Stock de '.$article_recipe->name.': '.$article_recipe->stock);
		Log::info('----------------------------------------');
	}

	static function getCantidadParaDescontar($article_recipe, $article_finished) {
		$cantidad_a_descontar = $article_recipe->pivot->amount * $article_finished->pivot->amount;
		$article_recipe->stock -= $cantidad_a_descontar;
		$article_recipe->save();
		Log::info('Se descontaron '.$cantidad_a_descontar.' unidades de '.$article_recipe->name);
	}

}