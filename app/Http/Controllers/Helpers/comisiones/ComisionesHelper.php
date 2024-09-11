<?php

namespace App\Http\Controllers\Helpers\comisiones;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Helpers\comisiones\RosMarComision;
use App\Models\SellerCommission;
use Illuminate\Support\Facades\Log;

class ComisionesHelper {
	
	public $sale;

	function __construct($sale) {

		$this->sale = $sale;
	}

	function crear_comision() {

		if (!is_null($this->sale->seller_id)) {

			Log::info('entro en crear_comision');
			
			$comision_function = UserHelper::user()->comision_funcion;

            if ($comision_function == 'ros_mar') {
				Log::info('entro en ros_mar comision_function');

                $comision = new RosMarComision($this->sale);

                $comision->crear_comision();
            }
		}
	}

    static function set_saldo($seller_commission, $es_un_pago = false) {
        $last = SellerCommission::where('seller_id', $seller_commission->seller_id)
                                    ->where('status', 'active')
                                    ->where('created_at', '<', $seller_commission->created_at)
                                    ->orderBy('created_at', 'DESC')
                                    ->first();
 		$saldo_anterior = 0;
        if (!is_null($last)) {
        	$saldo_anterior = $last->saldo;
        }

        if ($es_un_pago) {

        	$saldo = $saldo_anterior - $seller_commission->haber;
        } else {

        	$saldo = $saldo_anterior + $seller_commission->debe;
        }
        $seller_commission->saldo = $saldo;
        $seller_commission->save();
    }

}