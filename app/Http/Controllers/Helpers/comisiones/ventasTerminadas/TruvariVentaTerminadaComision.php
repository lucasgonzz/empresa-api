<?php

namespace App\Http\Controllers\Helpers\comisiones\ventasTerminadas;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\comisiones\ComisionesHelper;
use App\Models\SellerCommission;
use App\Models\User;
use App\Models\VentaTerminadaCommission;
use Illuminate\Support\Facades\Log;

class TruvariVentaTerminadaComision {
	
	public $sale;
	public $auth_user_id;
	public $seller_id;

	function __construct($sale, $auth_user_id) {

		$this->sale = $sale;
		$this->auth_user_id = $auth_user_id;
		$this->seller_id = null;

		$this->ct = new Controller();

		$this->set_seller_id(); 

		$this->crear_comision();
	}

	function set_seller_id() {

		$employee = User::find($this->auth_user_id);
		if ($employee) {

			$this->seller_id = $employee->seller_id;
		}
	}

	function crear_comision() {

		$monto_comision = $this->get_monto_comision();

		if ($monto_comision) {

			$description = 'Reparto de venta';

	        $seller_commission = SellerCommission::create([
	            'num'           => $this->ct->num('seller_commissions'),
	            'seller_id'     => $this->seller_id,
	            'sale_id'       => $this->sale->id,
	            'debe'          => $monto_comision,
	            'description'	=> $description,
	            'status'        => 'active',
	            'user_id'       => $this->ct->userId(),
	        ]);
		}
	}

	function get_monto_comision() {

		if ($this->seller_id) {
			
			$commission = VentaTerminadaCommission::where('user_id', $this->ct->userId())
											->where('seller_id', $this->seller_id)
											->first();
			if ($commission) {
				return $commission->monto_fijo;
			}
		}
		return null;
	}

}