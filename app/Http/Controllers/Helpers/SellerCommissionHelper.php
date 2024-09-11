<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\CommonLaravel\Helpers\Numbers;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\DiscountHelper;
use App\Http\Controllers\Helpers\SaleHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Helpers\comisiones\RosMarComision;
use App\Models\Commission;
use App\Models\SellerCommission;
use Illuminate\Support\Facades\Log;

class SellerCommissionHelper {

    static function checkCommissionStatus($current_acount, $pago) {
        if (!is_null($current_acount->sale)) {
            Log::info('Checkeando la comision de la venta n°'.$current_acount->sale->num);
        }
        $seller_commissions = SellerCommission::where('sale_id', $current_acount->sale_id)
                                                ->get();
        Log::info($seller_commissions);
        foreach ($seller_commissions as $seller_commission) {
            // Log::info('seller_commission id: '.$seller_commission->id);
            // Log::info('seller_commission status: '.$seller_commission->status);
            if ($seller_commission->status == 'inactive') {
                $seller_commission->status = 'active';
                Log::info('Se puso en active');
                $seller_commission->save();
                $seller_commission->pagada_por()->attach($pago->id);
            }
        }
    }
	
	static function commissionForSeller($current_acount) {

        if (!is_null($current_acount->seller_id)) {

            $comision_function = UserHelper::user()->comision_funcion;

            if ($comision_function == 'ros_mar') {

                $comision = new RosMarComision($current_acount);

                $comision->crear_comision();
            
            } else if ($comision_function == 'fenix') {

        		$commissions = Self::getCommissions($current_acount);

                foreach ($commissions as $commission) {

                    if (!Self::isExcept($commission, $current_acount->seller_id) 
                        && !Self::isForOnlySeller($commission, $current_acount->seller_id)) {
                        
                        if (count($commission->for_all_sellers) >= 1) {
                            foreach ($commission->for_all_sellers as $seller) {
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
            } 

        }
	}

    static function isExcept($commission, $seller_id) {
        $is_except = false;
        if (count($commission->except_sellers) >= 1) {
            foreach ($commission->except_sellers as $except_seller) {
                if ($except_seller->id == $seller_id) {
                    $is_except = true;
                }
            }
        }
        return $is_except;
    }

    static function isForOnlySeller($commission, $seller_id) {
        $is_for_only_seller = false;
        if (count($commission->for_only_sellers) >= 1) {
            foreach ($commission->for_only_sellers as $for_only_seller) {
                if ($for_only_seller->id != $seller_id) {
                    $is_for_only_seller = true;
                }
            }
        }
        return $is_for_only_seller;
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
        // Log::info('se creo commission para el vendedor '.$seller_commission->seller->name);
    }

    static function getCommissions($current_acount) {

        $total_discounts_percetage = DiscountHelper::getTotalDiscountsPercentage($current_acount->sale->discounts);

        $commissions = Commission::where('user_id', UserHelper::userId())->get();

        $commission_to_create = [];

        foreach ($commissions as $commission) {
            if (
                is_null($commission->sale_type_id) || $commission->sale_type_id == 0 
                || $commission->sale_type_id == $current_acount->sale->sale_type_id) {
                if ((is_null($commission->from) || $total_discounts_percetage >= $commission->from) && (is_null($commission->until) || $total_discounts_percetage <= $commission->until)) {
                    $commission_to_create[] = $commission;
                } 
            }
        }

        return $commission_to_create;
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
        if (!is_null($seller) && (boolean)$seller->commission_after_pay_sale) {
            return 'inactive';
        }
        return 'active';
    }
}