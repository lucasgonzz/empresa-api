<?php

namespace App\Http\Controllers\Helpers\comisiones;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\comisiones\ComisionesHelper;
use App\Http\Controllers\Helpers\comisiones\Helper;
use App\Models\Commission;
use App\Models\PromocionVinotecaCommission;
use App\Models\SellerCommission;
use Illuminate\Support\Facades\Log;

class TruvariComision {
	
	public $sale;
	public $en_blanco;

	function __construct($sale) {

		$this->sale = $sale;
		$this->seller = $sale->seller;

		$this->ct = new Controller();
	}

	function crear_comision() {

		$this->comision_articulos();
		
		$this->comision_promociones();
	}

	function comision_articulos() {

		$total = 0;

		$percentage = $this->get_percentage_comision();
		
		if ($percentage) {

			foreach ($this->sale->articles as $article) {
				
				$total_article = $article->pivot->price * $article->pivot->amount;

				$monto_comision = $total_article * $percentage / 100;

				$total += $monto_comision;
			}
		}

		Log::info('Total comision articulos: '.$total);

		if ($total > 0) {
			
	        $seller_commission = SellerCommission::create([
	            'num'           => $this->ct->num('seller_commissions'),
	            'seller_id'     => $this->sale->seller_id,
	            'sale_id'       => $this->sale->id,
	            'debe'          => $total,
	        	'status'        => Helper::get_status($this->sale),
	            'user_id'       => $this->ct->userId(),
	        ]);

	        ComisionesHelper::set_saldo($seller_commission);
		}
	}

	function comision_promociones() {

		$total = 0;

		$monto_comision = $this->get_monto_comision_promocion();

		$promociones_vendidas = 0;
		
		if ($monto_comision) {

			foreach ($this->sale->promocion_vinotecas as $promocion) {

				$total_comision = $monto_comision * $promocion->pivot->amount;
				
				$promociones_vendidas += $promocion->pivot->amount;

				$total += $total_comision;
			}
		}

		Log::info('Total comision promociones: '.$total);

		if ($total > 0) {
			$description = 'Venta de '.$promociones_vendidas.' promociones';
	        $seller_commission = SellerCommission::create([
	            'num'           => $this->ct->num('seller_commissions'),
	            'seller_id'     => $this->sale->seller_id,
	            'sale_id'       => $this->sale->id,
	            'debe'          => $total,
	        	'status'        => Helper::get_status($this->sale),
	            'description'	=> $description,
	            'user_id'       => $this->ct->userId(),
	        ]);

	        ComisionesHelper::set_saldo($seller_commission);
		}
	}

	function get_monto_comision_promocion() {
		$commission = PromocionVinotecaCommission::where('user_id', $this->ct->userId())
										->where('seller_id', $this->sale->seller_id)
										->first();
		if ($commission) {
			return $commission->monto_fijo;
		}
		return null;
	}

	function get_percentage_comision() {
		$commission = Commission::where('user_id', $this->ct->userId())
								->where('seller_id', $this->sale->seller_id)
								->first();

		if ($commission) {
			Log::info('Habia comision del '.$commission->percentage.'%');
			return $commission->percentage;
		}
		Log::info('No habia comision para seller id: '.$this->sale->seller_id);
		return null;
	}

}