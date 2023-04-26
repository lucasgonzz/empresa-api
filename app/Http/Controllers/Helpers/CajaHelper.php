<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\Helpers\SaleHelper;
use App\Models\CurrentAcount;
use App\Models\CurrentAcountPaymentMethod;
use App\Models\Sale;
use Carbon\Carbon;

class CajaHelper {
	
	static function getCaja($instance) {
		$sale_payment_methods = [];
		$vendido = 0;

		$payment_methods = CurrentAcountPaymentMethod::all();

		foreach ($payment_methods as $payment_method) {
			$sale_payment_methods[$payment_method->id] = [
				'name'	=> $payment_method->name,
				'total'	=> 0,
			];
		}

		$sales = Sale::where('user_id', $instance->userId())
						->where('created_at', Carbon::today())
						->get();
		foreach ($sales as $sale) {
			$vendido += SaleHelper::getTotalSale($sale);
			if (count($sale->current_acounts) == 0) {
				if (is_null($sale->current_acount_payment_method)) {
					$sale_payment_methods[3]['total'] += SaleHelper::getTotalSale($sale);
				} else {
					$sale_payment_methods[$sale->current_acount_payment_method_id]['total'] += SaleHelper::getTotalSale($sale);
				}
			}
		}

		$current_acounts = CurrentAcount::where('created_at', Carbon::today())
										->where('user_id', $instance->userId())
										->whereNotNull('haber')
										->get();
		foreach ($current_acounts as $current_acount) {
			if (is_null($current_acount->current_acount_payment_method)) {
				$sale_payment_methods[3]['total'] += $current_acount->haber;
			} else {
				$sale_payment_methods[$current_acount->current_acount_payment_method_id]['total'] += $current_acount->haber;
			}
		}

		return [
			'vendido' 				=> $vendido,
			'sale_payment_methods'	=> $sale_payment_methods,
		];

	}

}