<?php

namespace App\Http\Controllers\Helpers\comisiones;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\comisiones\ComisionesHelper;
use App\Http\Controllers\Helpers\comisiones\Helper;
use App\Models\SellerCommission;
use Illuminate\Support\Facades\Log;


class DistriCreoComision {
	   
    // Crea comision del 10% de la venta

    function crear_comision($sale) {

        $porcentaje_comision = 0.1;

        $ct = new Controller();

        $seller = $sale->seller;

        $seller_commission = SellerCommission::create([
            'num'           => $ct->num('seller_commissions'),
            'seller_id'     => $sale->seller_id,
            'percentage'    => 10,
            'sale_id'       => $sale->id,
            'debe'          => $sale->total * $porcentaje_comision,
            'status'        => Helper::get_status($sale),
            'user_id'       => $ct->userId(),
        ]);

        ComisionesHelper::set_saldo($seller_commission);

        Log::info('Se creo comision para sale_id '.$sale->id);
    }

}