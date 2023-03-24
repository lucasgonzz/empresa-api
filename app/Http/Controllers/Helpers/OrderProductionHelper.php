<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\CommonLaravel\Helpers\Numbers;
use App\Http\Controllers\CommonLaravel\Helpers\UserHelper;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\CurrentAcountHelper;
use App\Http\Controllers\Helpers\OrderProductionRecipe;
use App\Http\Controllers\Helpers\SaleHelper;
use App\Models\Article;
use App\Models\CurrentAcount;
use App\Models\OrderProduction;
use App\Models\OrderProductionStatus;
use App\Models\Sale;
use App\Notifications\OrderProductionNotification;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class OrderProductionHelper {

	static function checkFinieshed($order_production) {
		if ($order_production->finished && is_null($order_production->budget_id)) {
			Self::deleteCurrentAcount($order_production);
			Self::saveCurrentAcount($order_production);
	        CurrentAcountHelper::checkSaldos('client', $order_production->client_id);
	        Self::saveSale($order_production);
		}
	}

	static function deleteCurrentAcount($order_production) {
		$current_acount = CurrentAcount::where('order_production_id', $order_production->id)
										->first();
		if (!is_null($current_acount)) {
			$current_acount->delete();
			return true;
		}
		return false;
	}

	static function saveCurrentAcount($order_production) {
		$debe = Self::getTotal($order_production);
        $current_acount = CurrentAcount::create([
            'detalle'     			=> 'Order de Produccion N°'.$order_production->num,
            'debe'        			=> $debe,
            'status'      			=> 'sin_pagar',
            'client_id'   			=> $order_production->client_id,
            'order_production_id'   => $order_production->id,
            'description' 			=> null,
            'created_at'  			=> Carbon::now(),
        ]);
        $current_acount->saldo = Numbers::redondear(CurrentAcountHelper::getSaldo('client', $order_production->client_id, $current_acount) + $debe);
        $current_acount->save();
	}

	static function saveSale($order_production) {
		if (is_null($order_production->sale)) {
	        $ct = new Controller();
	        $sale = Sale::create([
	            'num' 					=> $ct->num('sales'),
	            'user_id' 				=> UserHelper::userId(),
	            'client_id' 			=> $order_production->client_id,
	            'order_production_id' 	=> $order_production->id,
            	'employee_id'           => SaleHelper::getEmployeeId(),
	            'save_current_acount' 	=> 0,
	        ]);
	        Self::attachSaleArticles($sale, $order_production);
        	$ct->sendAddModelNotification('Sale', $sale->id, false);
		}
	}

	static function attachSaleArticles($sale, $order_production) {
		foreach($order_production->articles as $article) {
			$sale->articles()->attach($article->id, [
				'amount'	=> $article->pivot->amount,
				'price'	    => $article->pivot->price,
				'discount'	=> $article->pivot->bonus,
			]);
		}
	}

	static function setArticles($order_productions) {
		foreach ($order_productions as $order_production) {
			foreach ($order_production->articles as $article) {
				foreach (Self::getStatuses() as $status) {
					$article->pivot->{'order_production_status_'.$status->id} = Self::getArticleFinishedAmount($order_production, $article, $status);  
				}
			}
		}
		return $order_productions;
	} 

	static function getArticleFinishedAmount($order_production, $article, $status) {
		$article_finished_res = null;
		foreach ($order_production->articles_finished as $article_finished) {
			if ($article_finished->id == $article->id && $article_finished->pivot->order_production_status_id == $status->id) {
				$article_finished_res = $article_finished;
				break;
			}
		}
		if (!is_null($article_finished_res)) {
			return $article_finished_res->pivot->amount;
		}
		return 0;
	}

	static function attachArticles($order_production, $articles) {
		$cantidades_actuales = OrderProductionRecipe::getCantidadesActuales($order_production);
		$ids = array_map(function($item) {
			return $item['id'];
		}, $articles);

		$order_production->articles()->detach($ids);
		$order_production->articles_finished()->detach($ids);
		
		foreach ($articles as $article) {
			if (isset($article['pivot']['delivered'])) {
				$delivered = $article['pivot']['delivered'];
			} else {
				$delivered = null;
			}
			if ($article['status'] == 'inactive') {
				$art = Article::find($article['id']);
				$art->bar_code = $article['bar_code'];
				$art->provider_code = $article['provider_code'];
				$art->name = $article['name'];
				$art->save();
			}
			$order_production->articles()->attach($article['id'], [
											'amount' 		=> $article['pivot']['amount'],
											'price' 		=> $article['pivot']['price'],
											'bonus' 		=> $article['pivot']['bonus'],
											'location' 		=> $article['pivot']['location'],
											'employee_id'   => isset($article['pivot']['employee_id']) ? $article['pivot']['employee_id'] : null,
											'delivered' 	=> $delivered,
										]);
		  	// $order_production_statuses = Self::getStatuses();
		  	// foreach ($order_production_statuses as $status) {
		  	// 	if (isset($article['pivot']['order_production_status_'.$status->id])) {
			// 	  	$order_production->articles_finished()->attach($article['id'], [
			// 	  									'order_production_status_id' => $status->id,
			// 	  									'amount' 					 => $article['pivot']['order_production_status_'.$status->id]
			// 	  								]);
		  	// 	}
		  	// }
		}
		// $order_production = OrderProduction::find($order_production->id);
		// OrderProductionRecipe::checkRecipes($order_production, $cantidades_actuales);
	}

	static function getTotal($order_production) {
		$total = 0;
		foreach ($order_production->articles as $article) {
			$total += Self::totalArticle($article);
		}
		return $total;
	}

	static function totalArticle($article) {
		$total = $article->pivot->price * $article->pivot->amount;
		if (!is_null($article->pivot->bonus)) {
			$total -= $total * (float)$article->pivot->bonus / 100;
		}
		return $total;
	}

	static function getStatuses() {
	  	return OrderProductionStatus::where('user_id', UserHelper::userId())
									->whereNotNull('position')
									->orderBy('position', 'ASC')
									->get();
	}

	static function getFisrtStatus() {
		$status = OrderProductionStatus::where('user_id', UserHelper::userId())
										->orderBy('position', 'ASC')
										->first();
		return $status->id;
	}

	static function sendCreatedMail($order_production, $send_mail) {
		if ($send_mail && $order_production->budget->client->email != '') {
			$subject = 'ORDEN DE PRODUCCION CREADA';
			$line = 'Empezamos a trabajar en tu pedido, actualmente se encuentra en la primer fase, nos comunicaremos por este medio para informarte sobre cualquier actualización en el estado de producción.';
			$order_production->budget->client->notify(new OrderProductionNotification($order_production, $subject, $line));
		}
	}

	static function sendUpdatedMail($order_production) {
		if (!is_null($order_production->client) && $order_production->client->email != '') {
			$subject = 'ORDEN DE PRODUCCION ACTUALIZADA';
			$line = 'Nos alegra informarte que tu pedido avanzo a la siguiente fase, nos comunicaremos por este medio para informarte sobre cualquier actualización en el estado de producción.';
			$order_production->client->notify(new OrderProductionNotification($order_production, $subject, $line));
		}
	}

}