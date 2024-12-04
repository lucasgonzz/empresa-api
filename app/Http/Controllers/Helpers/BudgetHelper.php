<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\CurrentAcountHelper;
use App\Http\Controllers\Helpers\Numbers;
use App\Http\Controllers\Helpers\SaleHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\SaleController;
use App\Models\Article;
use App\Models\Budget;
use App\Models\CurrentAcount;
use App\Models\OrderProduction;
use App\Models\Sale;
use App\Notifications\BudgetCreated;
use App\Notifications\CreatedSale;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class BudgetHelper {

	static function sendMail($budget, $send_mail) {
		if ($send_mail == 1 && $budget->client->email != '') {
			$budget->client->notify(new BudgetCreated($budget));
		}
	}

	static function checkStatus($budget, $previus_articles) {
		Self::deleteCurrentAcount($budget);
	    Self::deleteSale($budget);
		if ($budget->budget_status->name == 'Confirmado') {

	        Self::saveSale($budget, $previus_articles);
		} 
	    CurrentAcountHelper::checkSaldos('client', $budget->client_id);
	}

	static function saveSale($budget, $previus_articles) {
		if (is_null($budget->sale)) {
	        $ct = new Controller();
	        $sale = Sale::create([
	            'num' 					=> $ct->num('sales'),
	            'user_id' 				=> UserHelper::userId(),
	            'client_id' 			=> $budget->client_id,
	            'budget_id' 			=> $budget->id,
	            'observations' 			=> $budget->observations,
	            'total' 				=> $budget->total,
	            'address_id' 			=> $budget->address_id,
            	'price_type_id'         => Self::get_price_type_id($budget),
            	'employee_id'           => SaleHelper::getEmployeeId(),
	            'save_current_acount' 	=> Self::get_guardar_cuenta_corriente($budget),
	            'to_check'				=> UserHelper::hasExtencion('check_sales') ? 1 : 0,
	            'terminada'				=> UserHelper::hasExtencion('check_sales') ? 0 : 1,
	        ]);
	        Self::attachSaleArticles($sale, $budget, $previus_articles);
	        Self::attachSaleDiscountsAndSurchages($sale, $budget);

	        if (!$sale->to_check) {
	        	SaleHelper::create_current_acount($sale);
	        }

        	$ct->sendAddModelNotification('Sale', $sale->id, false);
		}
	}

	static function get_price_type_id($budget) {

		if (!is_null($budget->price_type_id)) {
			return $budget->price_type_id;
		}

		$client = $budget->client;
		
		if (!is_null($client) 
			&& !is_null($client->price_type_id)) {

			return $client->price_type_id;
		}
		return null;
	}

	static function get_guardar_cuenta_corriente($budget) {
		if (UserHelper::hasExtencion('guardad_cuenta_corriente_despues_de_facturar')) {
			if (!is_null($budget->client) && !$budget->client->pasar_ventas_a_la_cuenta_corriente_sin_esperar_a_facturar) {
				return false;
			}
		}
		return true;		
	}

	static function attachSaleArticles($sale, $budget, $previus_articles) {
		$has_extencion_check_sales = UserHelper::hasExtencion('check_sales');
		foreach($budget->articles as $article) {
			$sale->articles()->attach($article->id, [
				'amount'			=> $article->pivot->amount,
				'checked_amount'	=> Self::get_checked_amount($has_extencion_check_sales, $article),
				'price'	    		=> $article->pivot->price,
				'price_type_personalizado_id'	    		=> $article->pivot->price_type_personalizado_id,
				'discount'			=> $article->pivot->bonus,
			]);

			if (!$has_extencion_check_sales) {

            	ArticleHelper::discountStock($article->id, $article->pivot->amount, $sale, [], false, null);
			}


		}
	}

	static function get_checked_amount($has_extencion_check_sales, $article) {
		$checked_amount = null;
		if ($has_extencion_check_sales) {
			$stock_actual = $article->stock;
			if ($article->pivot->amount > $stock_actual) {
				if ($stock_actual < 0) {
					$checked_amount = 0;
				} else {
					$checked_amount = $stock_actual;
				}
			}
		}
		return $checked_amount;
	}

	static function attachSaleDiscountsAndSurchages($sale, $budget) {
		foreach ($budget->discounts as $discount) {
			$sale->discounts()->attach($discount->id, [
				'percentage'	=> $discount->pivot->percentage,
			]);
		}
		foreach ($budget->surchages as $surchage) {
			$sale->surchages()->attach($surchage->id, [
				'percentage'	=> $surchage->pivot->percentage,
			]);
		}
	}

	static function saveCurrentAcount($budget) {
		$debe = Self::getTotal($budget);
        $current_acount = CurrentAcount::create([
            'detalle'     => 'Presupuesto N°'.$budget->num,
            'debe'        => $debe,
            'status'      => 'sin_pagar',
            'client_id'   => $budget->client_id,
            'budget_id'   => $budget->id,
            'description' => null,
            'created_at'  => Carbon::now(),
        ]);
        Log::info('Se actualizo saldo a '.$debe);
        $current_acount->saldo = Numbers::redondear(CurrentAcountHelper::getSaldo('client', $budget->client_id, $current_acount) + $debe);
        $current_acount->save();
	}

	static function deleteCurrentAcount($budget) {
		$current_acount = CurrentAcount::where('budget_id', $budget->id)
										->first();
		if (!is_null($current_acount)) {
			$current_acount->delete();
			return true;
		}
		return false;
	}

	static function deleteSale($budget) {
		$sale = Sale::where('budget_id', $budget->id)
										->first();
		if (!is_null($sale)) {
			Log::info(Auth()->user()->name.' va a eliminar la venta N° '.$sale->num.' por actualizar el presupuesto N° '.$budget->num);
			$ct = new SaleController();
			$ct->destroy($sale->id);
			// $sale->delete();
			return true;
		}
		return false;
	}

	static function getTotal($budget) {
		$total = 0;
		foreach ($budget->articles as $article) {
			$total += Self::totalArticle($article);
		}
		foreach ($budget->discounts as $discount) {
			$total -= $discount->pivot->percentage * $total / 100;
		}
		foreach ($budget->surchages as $surchage) {
			$total += $surchage->pivot->percentage * $total / 100;
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

	static function attachArticles($budget, $articles) {
		$budget->articles()->detach();
		foreach ($articles as $article) {
			$id = (int)$article['id'];
			$amount = $article['pivot']['amount'];
			$bonus = $article['pivot']['bonus'];
			$location = $article['pivot']['location'];
			$price = $article['pivot']['price'];
			$price_type_personalizado_id = isset($article['pivot']['price_type_personalizado_id']) ? $article['pivot']['price_type_personalizado_id'] : null;
			
			if ($article['status'] == 'inactive' && $id > 0) {
				$art = Article::find($article['id']);
				$art->bar_code 		= $article['bar_code'];
				$art->provider_code = $article['provider_code'];
				$art->name 			= $article['name'];
				$art->save();
			}
			$budget->articles()->attach($article['id'], [
									'amount' 	=> $amount,
									'price' 	=> $price,
									'bonus' 	=> $bonus,
									'location' 	=> $location,
									'price_type_personalizado_id' 	=> $price_type_personalizado_id,
								]);
		}		
	}

}