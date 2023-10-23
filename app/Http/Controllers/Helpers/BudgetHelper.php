<?php

namespace App\Http\Controllers\Helpers;

use App\Models\Article;
use App\Models\Budget;
use App\Models\CurrentAcount;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\CurrentAcountHelper;
use App\Http\Controllers\Helpers\Numbers;
use App\Http\Controllers\Helpers\SaleHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Notifications\BudgetCreated;
use App\Notifications\CreatedSale;
use App\Models\OrderProduction;
use App\Models\Sale;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class BudgetHelper {

	static function sendMail($budget, $send_mail) {
		if ($send_mail == 1 && $budget->client->email != '') {
			$budget->client->notify(new BudgetCreated($budget));
		}
	}

	static function checkStatus($budget) {
		Self::deleteCurrentAcount($budget);
	    Self::deleteSale($budget);
		if ($budget->budget_status->name == 'Confirmado') {
			Self::saveCurrentAcount($budget);
	        Self::saveSale($budget);
		} 
	    CurrentAcountHelper::checkSaldos('client', $budget->client_id);
	}

	static function saveSale($budget) {
		if (is_null($budget->sale)) {
	        $ct = new Controller();
	        $sale = Sale::create([
	            'num' 					=> $ct->num('sales'),
	            'user_id' 				=> UserHelper::userId(),
	            'client_id' 			=> $budget->client_id,
	            'budget_id' 			=> $budget->id,
            	'employee_id'           => SaleHelper::getEmployeeId(),
	            'save_current_acount' 	=> 0,
	        ]);
	        Self::attachSaleArticles($sale, $budget);
	        Self::attachSaleDiscountsAndSurchages($sale, $budget);
        	$ct->sendAddModelNotification('Sale', $sale->id, false);
		}
	}

	static function attachSaleArticles($sale, $budget) {
		foreach($budget->articles as $article) {
			$sale->articles()->attach($article->id, [
				'amount'	=> $article->pivot->amount,
				'price'	    => $article->pivot->price,
				'discount'	=> $article->pivot->bonus,
			]);
		}
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
			$sale->delete();
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
								]);
		}		
	}

}