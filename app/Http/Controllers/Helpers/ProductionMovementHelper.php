<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\StockMovementController;
use App\Models\OrderProductionStatus;
use App\Models\ProductionMovement;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class ProductionMovementHelper {

	static function checkRecipe($production_movement, $instance, $last_amount = 0, $increase_stock = false) {
		if (!is_null($production_movement->article->recipe)) {
			foreach ($production_movement->article->recipe->articles as $article_recipe) {
				if (!is_null($article_recipe->pivot->order_production_status_id)
					&& $article_recipe->pivot->order_production_status_id != 0
					&& Self::restar_insumos_en_este_estado($instance, $production_movement, $article_recipe)) {
					// Log::info('--------------------------');
					// Log::info('Entro en receta de '.$production_movement->article->name.'. En Paso productivo '.$production_movement->order_production_status->name.' con insumo '.$article_recipe->name. ' que en la reseta esta en el paso '.$pivot_order_production_status->name);
					if ($increase_stock) {
						Self::increaseStock($article_recipe, $production_movement, $instance);
					} else {
						Self::discountStock($article_recipe, $production_movement, $instance, $last_amount);
					}
				}
			}
		}
		Self::checkIsLastStatus($production_movement, $instance, $increase_stock);
	}

	static function restar_insumos_en_este_estado($instance, $production_movement, $article_recipe) {
		$user = UserHelper::getFullModel();

		// Log::info($article_recipe->name.' order_production_status_id: '.$article_recipe->pivot->order_production_status_id);

		$pivot_order_production_status = $instance->getModelBy('order_production_statuses', 'id', $article_recipe->pivot->order_production_status_id);
		
		$current_position = $production_movement->order_production_status->position;
		if ($user->discount_stock_from_recipe_after_advance_to_next_status) {
			$current_position -= 1;
		}
		return $current_position == $pivot_order_production_status->position;
	}

	static function checkArticleAddresses($production_movement) {
		foreach ($production_movement->article->recipe->articles as $article) {
        	ArticleHelper::setArticleStockFromAddresses($article);
		}
	}


	// $from_destroy es $increase_stock
	// Si es false es porque vino desde store
	// Si es true es porque vino desde delete
	static function checkIsLastStatus($production_movement, $instance, $from_destroy) {
		$last_status = OrderProductionStatus::where('user_id', $instance->userId())
											->whereNotNull('position')
											->orderBy('position', 'DESC')
											->first();
		if ($production_movement->order_production_status_id == $last_status->id) {
			
			$request = new \Illuminate\Http\Request();
            $request->model_id = $production_movement->article_id;

            if ($from_destroy) {
            	$request->concepto = 'Eliminacion de Prod terminada';
            	$request->amount = -$production_movement->amount;
            } else {
            	$request->concepto = 'Produccion terminada';
            	$request->amount = $production_movement->amount;
            }

            Log::info('checkIsLastStatus');
            Log::info('count addresses: '.count($production_movement->article->addresses));
            Log::info('!is_null address_id: '.!is_null($production_movement->article->recipe->address_id));
			if (count($production_movement->article->addresses) >= 1 
				&& !is_null($production_movement->article->recipe->address_id)) {
            	$request->to_address_id = $production_movement->article->recipe->address_id;
				Log::info('entro y se puse address_id: '.$production_movement->article->recipe->address_id);
			}

			$stock_movement_ct = new StockMovementController();
            $stock_movement_ct->store($request, false);
        	// $instance->sendAddModelNotification('article', $production_movement->article->id, false);
		}
	}

	static function discountStock($article_recipe, $production_movement, $instance, $last_amount) {
		if (!is_null($article_recipe->stock) || count($article_recipe->addresses) >= 1) {
			$amount_to_discount = $production_movement->amount - $last_amount;
			$amount_to_discount = $article_recipe->pivot->amount * $amount_to_discount;
    		

			$stock_movement_ct = new StockMovementController();
    		$request = new \Illuminate\Http\Request();
			$request->model_id = $article_recipe->id;
			$request->amount = -$amount_to_discount;
			$request->concepto = 'Prod. '.$production_movement->article->name;
			
			if (!is_null($article_recipe->pivot->address_id) && count($article_recipe->addresses) >= 1) {
				$request->from_address_id = $article_recipe->pivot->address_id;
			} else {
				$request->amount = -$amount_to_discount;
			}
           	$stock_movement_ct->store($request);

        	// $instance->sendAddModelNotification('article', $article_recipe->id, false);
			Log::info('Nuevo Stock de '.$article_recipe->name.': '.$article_recipe->stock);
			// Log::info('Nuevo Stock de '.$article_recipe->name.': '.$article_recipe->stock);
		}
		// Log::info('----------------------------------------');
	}

	static function increaseStock($article_recipe, $production_movement, $instance) {
		if (!is_null($article_recipe->stock) || count($article_recipe->addresses) >= 1) {
			$amount_to_increase = $article_recipe->pivot->amount * $production_movement->amount;
			
			$stock_movement_ct = new StockMovementController();
    		$request = new \Illuminate\Http\Request();
			$request->model_id = $article_recipe->id;
			$request->amount = $amount_to_increase;
			$request->concepto = 'Eliminacion Mov. Prod. '.$production_movement->article->name;

			if (!is_null($article_recipe->pivot->address_id) && count($article_recipe->addresses) >= 1) {
				$request->to_address_id = $article_recipe->pivot->address_id;
			} 
           	$stock_movement_ct->store($request);
		}

	}

	static function setCurrentAmount($production_movement, $instance, $last_amount = 0) {
		if ($production_movement->order_production_status->position > 1) {
			$previus_production_status = OrderProductionStatus::where('user_id', $instance->userId())
																->where('position', $production_movement->order_production_status->position - 1)
																->first();
			$previus_production_movement = ProductionMovement::where('article_id', $production_movement->article_id)
															->where('id', '!=', $production_movement->id)
															->orderBy('created_at', 'DESC')
															->where('order_production_status_id', $previus_production_status->id)
															->first();
			if (!is_null($previus_production_movement)) {
				$amount_to_discount = $production_movement->amount - $last_amount;
				$previus_production_movement->current_amount -= $amount_to_discount;
				$previus_production_movement->save();
			} 
		}
		
		$same_production_movement = ProductionMovement::where('article_id', $production_movement->article_id)
														->where('id', '!=', $production_movement->id)
														->where('order_production_status_id', $production_movement->order_production_status_id)
														->orderBy('created_at', 'DESC')
														->first();
		if (!is_null($same_production_movement)) {
			$amount_to_increase = $same_production_movement->current_amount - $last_amount;
			$production_movement->current_amount += $amount_to_increase;
			$production_movement->save();
		}
	}


	/*
		
		* Si el movimiento NO pertenece al PRIMER estado de produccion:
			1- Se busca el estado de produccion anterior al estado de produccion del movimiento
				que se quiere eliminar
			2- Su busca el ultimo movimineto que pertenezca a ese estado anterior
			3- Se aumenta la cantidad actual de ese movimiento previo con las cantidades que se
				estan eliminando 

	*/

	static function delete($production_movement, $instance) {
		if ($production_movement->order_production_status->position > 1) {
			$previus_production_status = OrderProductionStatus::where('user_id', $instance->userId())
																->where('position', $production_movement->order_production_status->position - 1)
																->first();
			$previus_production_movement = ProductionMovement::where('article_id', $production_movement->article_id)
															->where('id', '!=', $production_movement->id)
															->orderBy('created_at', 'DESC')
															->where('order_production_status_id', $previus_production_status->id)
															->first();
			if (!is_null($previus_production_movement)) {

				$previus_production_movement->current_amount += $production_movement->amount;
				$previus_production_movement->save();
				
			} 
		}
	}
	
}