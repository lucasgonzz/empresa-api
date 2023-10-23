<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Models\OrderProductionStatus;
use App\Models\ProductionMovement;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class ProductionMovementHelper {

	static function checkRecipe($production_movement, $instance, $last_amount = 0, $increase_stock = false) {
		if (!is_null($production_movement->article->recipe)) {
			foreach ($production_movement->article->recipe->articles as $article_recipe) {
				$pivot_order_production_status = $instance->getModelBy('order_production_statuses', 'id', $article_recipe->pivot->order_production_status_id);
				if ($production_movement->order_production_status->position - 1 == $pivot_order_production_status->position) {
					Log::info('--------------------------');
					Log::info('Entro en receta de '.$production_movement->article->name.'. En Paso productivo '.$production_movement->order_production_status->name.' con insumo '.$article_recipe->name. ' que en la reseta esta en el paso '.$pivot_order_production_status->name);
					// Log::info('Necesita '.$article_recipe->pivot->amount.' de '.$article_recipe->name);
					if ($increase_stock) {
						Self::increaseStock($article_recipe, $production_movement, $instance);
					} else {
						Self::discountStock($article_recipe, $production_movement, $instance, $last_amount);
					}
				}
			}
		}
		Self::checkIsLastStatus($production_movement, $instance);
	}

	static function checkArticleAddresses($production_movement) {
		foreach ($production_movement->article->recipe->articles as $article) {
        	ArticleHelper::setArticleStockFromAddresses($article);
		}
	}

	static function checkIsLastStatus($production_movement, $instance) {
		$last_status = OrderProductionStatus::where('user_id', $instance->userId())
											->whereNotNull('position')
											->orderBy('position', 'DESC')
											->first();
		if ($production_movement->order_production_status_id == $last_status->id) {
			if (!is_null($production_movement->article->stock)) {
				$production_movement->article->stock += $production_movement->amount;
				$production_movement->article->save();				
        		$instance->sendAddModelNotification('article', $production_movement->article->id, false);
			} 
		}
	}

	static function discountStock($article_recipe, $production_movement, $instance, $last_amount) {
		if (!is_null($article_recipe->stock) || count($article_recipe->addresses) >= 1) {
			$amount_to_discount = $production_movement->amount - $last_amount;
			$amount_to_discount = $article_recipe->pivot->amount * $amount_to_discount;
			
			if (!is_null($article_recipe->pivot->address_id) && count($article_recipe->addresses) >= 1) {
				foreach ($article_recipe->addresses as $address) {
					if ($address->id == $article_recipe->pivot->address_id) {
						Log::info('Stock de '.$article_recipe->name.'en la direccion '.$address->street.': '.$address->pivot->amount);
						$new_amount = $address->pivot->amount - $amount_to_discount;
						$article_recipe->addresses()->updateExistingPivot($address->id, [
							'amount' => $new_amount,
						]);
					}
				}
			} else {
				Log::info('Stock de '.$article_recipe->name.': '.$article_recipe->stock);
				Log::info('Se descontaran '.$amount_to_discount);
				$article_recipe->stock -= $amount_to_discount;
				$article_recipe->save();
			}
        	$instance->sendAddModelNotification('article', $article_recipe->id, false);
			Log::info('Nuevo Stock de '.$article_recipe->name.': '.$article_recipe->stock);
			// Log::info('Nuevo Stock de '.$article_recipe->name.': '.$article_recipe->stock);
		}
		// Log::info('----------------------------------------');
	}

	static function increaseStock($article_recipe, $production_movement, $instance) {
		Log::info('Stock de '.$article_recipe->name.': '.$article_recipe->stock);
		Log::info('Se repondran '.$article_recipe->pivot->amount * $production_movement->amount);

		if (!is_null($article_recipe->stock) || count($article_recipe->addresses) >= 1) {
			$amount_to_increase = $article_recipe->pivot->amount * $production_movement->amount;

			if (!is_null($article_recipe->pivot->address_id) && count($article_recipe->addresses) >= 1) {
				foreach ($article_recipe->addresses as $address) {
					if ($address->id == $article_recipe->pivot->address_id) {
						Log::info('Stock de '.$article_recipe->name.'en la direccion '.$address->street.': '.$address->pivot->amount);
						$new_amount = $address->pivot->amount - $amount_to_increase;
						$article_recipe->addresses()->updateExistingPivot($address->id, [
							'amount' => $new_amount,
						]);
					}
				}
			} else if (!is_null($article_recipe->stock)) {
				$article_recipe->stock += $article_recipe->pivot->amount * $production_movement->amount;
				$article_recipe->save();
	        	$instance->sendAddModelNotification('article', $article_recipe->id, false);
				Log::info('Nuevo Stock de '.$article_recipe->name.': '.$article_recipe->stock);
			}
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
				Log::info('Se actualizo current_amount del order_status: '.$previus_production_movement->order_production_status->name.'. Tenia '.$previus_production_movement->current_amount);
				$previus_production_movement->current_amount += $production_movement->amount;
				$previus_production_movement->save();
				Log::info('Ahora tiene '.$previus_production_movement->current_amount);
			} 
		}
	}
	
}