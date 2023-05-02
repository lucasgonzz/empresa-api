<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\DiscountHelper;
use App\Models\Commission;
use App\Models\SellerCommission;
use Illuminate\Support\Facades\Log;

class SellerCommissionHelper {
	
	static function commissionForSeller($current_acount) {
        $ct = new Controller();
		$commission = Self::getCommission($current_acount);
		SellerCommission::create([
            'num'           => $ct->num('seller_commissions'),
			'seller_id'		=> $current_acount->seller_id,
			'sale_id'		=> $current_acount->sale_id,
			'percentage'	=> $commission['percentage'],
			'amount'		=> $commission['amount'],
		]);
	}

    static function getCommission($current_acount) {
        $total_discounts_percetage = DiscountHelper::getTotalDiscountsPercentage($current_acount->sale->discounts);
        Log::info('total_discounts_percetage: '.$total_discounts_percetage);
        $commissions = Commission::where('user_id', UserHelper::userId())->get();
        $commission_for_seller = null;
        foreach ($commissions as $commission) {
            Log::info('comparando $commission->sale_type_id = '.$commission->sale_type_id.' con $current_acount->sale->sale_type_id = '.$current_acount->sale->sale_type_id);
            if ($commission->sale_type_id == $current_acount->sale->sale_type_id) {
                Log::info('entro con $commission->sale_type_id = '.$commission->sale_type_id);
                Log::info('comparando $total_discounts_percetage = '.$total_discounts_percetage.' >= que $commission->from = '.$commission->from. ' && $total_discounts_percetage = '.$total_discounts_percetage.' <= que $commission->until = '.$commission->until);
                if ($total_discounts_percetage >= $commission->from && $total_discounts_percetage <= $commission->until) {
                    $commission_for_seller = $commission;
                } 
            }
        }
        return [
        	'percentage'	=> $commission_for_seller->amount,
        	'amount'		=> $current_acount->debe * $commission_for_seller->amount / 100,
        ];
    }
}