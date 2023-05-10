<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\CommonLaravel\Helpers\Numbers;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\DiscountHelper;
use App\Http\Controllers\Helpers\SaleHelper;
use App\Models\Commission;
use App\Models\SellerCommission;
use Illuminate\Support\Facades\Log;

class SellerCommissionHelper {

    static function checkCommissionStatus($current_acount) {
        // Log::info('Checkeando la comision de la venta n°'.$current_acount->sale->num);
        $seller_commissions = SellerCommission::where('sale_id', $current_acount->sale_id)
                                                ->get();
        foreach ($seller_commissions as $seller_commission) {
            // Log::info('seller_commission id: '.$seller_commission->id);
            // Log::info('seller_commission status: '.$seller_commission->status);
            if ($seller_commission->status == 'inactive') {
                $seller_commission->status = 'active';
                Log::info('Se puso en active');
                $seller_commission->save();
            }
        }
    }
	
	static function commissionForSeller($current_acount) {
		$commissions = Self::getCommissions($current_acount);
        foreach ($commissions as $commission) {
            if (count($commission->sellers) >= 1) {
                foreach ($commission->sellers as $seller) {
                    Self::createCommission([
                        'seller_id'     => $seller->id,
                        'sale_id'       => $current_acount->sale_id,
                        'description'   => Self::getDescription($current_acount),
                        'percentage'    => $seller->pivot->percentage,
                        'debe'          => $current_acount->debe * $seller->pivot->percentage / 100,
                        'status'        => Self::getStatus($seller),
                    ]);
                }
            } else {
                Self::createCommission([
                    'seller_id'     => $current_acount->seller_id,
                    'sale_id'       => $current_acount->sale_id,
                    'description'   => Self::getDescription($current_acount),
                    'percentage'    => $commission->percentage,
                    'debe'          => $current_acount->debe * $commission->percentage / 100,
                    'status'        => Self::getStatus($current_acount->seller),
                ]);
            }
        }
	}

    static function createCommission($data) {
        $ct = new Controller();
        $seller_commission = SellerCommission::create([
            'num'           => $ct->num('seller_commissions'),
            'seller_id'     => $data['seller_id'],
            'sale_id'       => $data['sale_id'],
            'description'   => $data['description'],
            'percentage'    => $data['percentage'],
            'debe'          => $data['debe'],
            'status'        => $data['status'],
            'user_id'       => $ct->userId(),
        ]);
        $seller_commission->saldo = Self::getSaldo($seller_commission) + $seller_commission->debe;
        $seller_commission->save();
        Log::info('se creo commission para el vendedor '.$seller_commission->seller->name);
    }

    static function getCommissions($current_acount) {
        $total_discounts_percetage = DiscountHelper::getTotalDiscountsPercentage($current_acount->sale->discounts);
        // Log::info('total_discounts_percetage: '.$total_discounts_percetage);
        $commissions = Commission::where('user_id', UserHelper::userId())->get();
        $commission_to_create = [];
        foreach ($commissions as $commission) {
            // Log::info('comparando $commission->sale_type_id = '.$commission->sale_type_id.' con $current_acount->sale->sale_type_id = '.$current_acount->sale->sale_type_id);
            if (is_null($commission->sale_type_id) || $commission->sale_type_id == 0 || $commission->sale_type_id == $current_acount->sale->sale_type_id) {
                // Log::info('entro con $commission->sale_type_id = '.$commission->sale_type_id);
                // Log::info('comparando $total_discounts_percetage = '.$total_discounts_percetage.' >= que $commission->from = '.$commission->from. ' && $total_discounts_percetage = '.$total_discounts_percetage.' <= que $commission->until = '.$commission->until);
                if ((is_null($commission->from) || $total_discounts_percetage >= $commission->from) && (is_null($commission->until) || $total_discounts_percetage <= $commission->until)) {
                    $commission_to_create[] = $commission;
                } 
            }
        }
        // Log::info('commission_to_create:');
        // Log::info($commission_to_create);
        return $commission_to_create;
        if (is_null($commission_for_seller)) {
            return null;
        } else {
            return [
            	'percentage'	=> $commission_for_seller->amount,
            	'debe'		    => $current_acount->debe * $commission_for_seller->amount / 100,
            ];
        }
    }

    static function getDescription($current_acount = null) {
        if (!is_null($current_acount) && !is_null($current_acount->sale)) {
            return 'Venta N°'.$current_acount->sale->num.' ($'.Numbers::price(SaleHelper::getTotalSale($current_acount->sale)).')';
        }
        return 'Pago a vendedor';
    }

    static function getSaldo($seller_commission) {
        $last = SellerCommission::where('seller_id', $seller_commission->seller_id)
                                    ->where('status', 'active')
                                    ->where('created_at', '<', $seller_commission->created_at)
                                    ->orderBy('created_at', 'DESC')
                                    ->first();
        if (is_null($last)) {
            return 0;
        }
        return $last->saldo;
    }

    static function checkSaldos($from_seller_commission) {
        $seller_commissions = SellerCommission::where('seller_id', $from_seller_commission->seller_id)
                                                ->where('created_at', '>', $from_seller_commission->created_at)
                                                ->get();
        foreach ($seller_commissions as $seller_commission) {
            if (!is_null($seller_commission->haber)) {
                $seller_commission->saldo = Self::getSaldo($seller_commission) - $seller_commission->haber;
            } else {
                $seller_commission->saldo = Self::getSaldo($seller_commission) + $seller_commission->debe;
            }
            $seller_commission->save();
        }
    }

    static function getStatus($seller) {
        if ((boolean)$seller->commission_after_pay_sale) {
            return 'inactive';
        }
        return 'active';
    }
}