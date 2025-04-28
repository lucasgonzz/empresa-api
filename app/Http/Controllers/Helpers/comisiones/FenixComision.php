<?php

namespace App\Http\Controllers\Helpers\comisiones;

use App\Http\Controllers\CommonLaravel\Helpers\Numbers;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\DiscountHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Helpers\comisiones\Helper;
use App\Models\Commission;
use App\Models\SellerCommission;
use Illuminate\Support\Facades\Log;

class FenixComision {
	
	public $sale;
	public $en_blanco;

	function __construct($sale) {

		$this->sale = $sale;

		$this->ct = new Controller();
	}

	function crear_comision() {

		$commissions = $this->comisiones_a_crear();

        Log::info(count($commissions).' comisiones a crear');

        foreach ($commissions as $commission) {

            if (!$this->isExcept($commission, $this->sale->seller_id) 
                && !$this->isForOnlySeller($commission, $this->sale->seller_id)) {
                
                if (count($commission->for_all_sellers) >= 1) {
                    foreach ($commission->for_all_sellers as $seller) {
                        $this->createCommission([
                            'seller_id'     => $seller->id,
                            'sale_id'       => $this->sale->id,
                            'description'   => $this->getDescription(),
                            'percentage'    => $seller->pivot->percentage,
                            'debe'          => $this->sale->total * $seller->pivot->percentage / 100,
                            'status'        => Helper::get_status($this->sale),
                        ]);
                    }
                } else {
                    $this->createCommission([
                        'seller_id'     => $this->sale->seller_id,
                        'sale_id'       => $this->sale->id,
                        'description'   => $this->getDescription(),
                        'percentage'    => $commission->percentage,
                        'debe'          => $this->sale->total * $commission->percentage / 100,
                        'status'        => Helper::get_status($this->sale),
                    ]);
                }
            }
        }
	}



    function isExcept($commission, $seller_id) {
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

    function isForOnlySeller($commission, $seller_id) {
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

    function createCommission($data) {
        $seller_commission = SellerCommission::create([
            'num'           => $this->ct->num('seller_commissions'),
            'seller_id'     => $data['seller_id'],
            'sale_id'       => $data['sale_id'],
            'description'   => $data['description'],
            'percentage'    => $data['percentage'],
            'debe'          => $data['debe'],
            'status'        => $data['status'],
            'user_id'       => $this->ct->userId(),
        ]);
        $seller_commission->saldo = $this->getSaldo($seller_commission) + $seller_commission->debe;
        $seller_commission->save();
        // Log::info('se creo commission para el vendedor '.$seller_commission->seller->name);
    }

    function comisiones_a_crear() {

        $total_discounts_percetage = DiscountHelper::getTotalDiscountsPercentage($this->sale->discounts);

        Log::info('total_discounts_percetage: '.$total_discounts_percetage);

        $commissions = Commission::where('user_id', $this->sale->user_id)->get();
        Log::info('commissions: '.count($commissions));

        $commission_to_create = [];

        foreach ($commissions as $commission) {
            if (
                is_null($commission->sale_type_id) || $commission->sale_type_id == 0 
                || $commission->sale_type_id == $this->sale->sale_type_id
            ) {
                if (
                	(
                		is_null($commission->from) 
                		|| $total_discounts_percetage >= $commission->from) 
                		&& (is_null($commission->until) 
                		|| $total_discounts_percetage <= $commission->until
                	)
                ) {
                    $commission_to_create[] = $commission;
                } 
            }
        }

        return $commission_to_create;
    }

    function getDescription() {
        if (!is_null($this->sale) && !is_null($this->sale)) {
            return 'Venta N°'.$this->sale->num.' ($'.Numbers::price($this->sale->total).')';
        }
        return 'Pago a vendedor';
    }

    function getSaldo($seller_commission) {
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

    function checkSaldos($from_seller_commission) {
        $seller_commissions = SellerCommission::where('seller_id', $from_seller_commission->seller_id)
                                                ->where('created_at', '>', $from_seller_commission->created_at)
                                                ->get();
        foreach ($seller_commissions as $seller_commission) {
            if (!is_null($seller_commission->haber)) {
                $seller_commission->saldo = $this->getSaldo($seller_commission) - $seller_commission->haber;
            } else {
                $seller_commission->saldo = $this->getSaldo($seller_commission) + $seller_commission->debe;
            }
            $seller_commission->save();
        }
    }

    function getStatus($seller) {
        if (!is_null($seller) && (boolean)$seller->commission_after_pay_sale) {
            return 'inactive';
        }
        return 'active';
    }

}