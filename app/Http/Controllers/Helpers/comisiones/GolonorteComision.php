<?php

namespace App\Http\Controllers\Helpers\comisiones;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\comisiones\ComisionesHelper;
use App\Http\Controllers\Helpers\comisiones\Helper;
use App\Models\SellerCommission;
use Illuminate\Support\Facades\Log;

class GolonorteComision {
	
	public $sale;

	function __construct($sale) {

		$this->sale = $sale;
		$this->seller = $sale->seller;

		$this->ct = new Controller();
	}

	function crear_comision() {

		$total = 0;

		foreach ($this->sale->articles as $article) {


			$percentage = $this->get_percentage_comision($article);

			if ($percentage) {
				
				$total_article = $article->pivot->price * $article->pivot->amount;

				$monto_comision = $total_article * $percentage / 100;

				$total += $monto_comision;
			}
		}

		Log::info('Total comision: '.$total);

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

	function get_percentage_comision($article) {
		if ($article->category) {

			$seller_category = $this->seller->categories->firstWhere('id', $article->category_id);

			if ($seller_category) {
				return $seller_category->pivot->percentage;
			}
		}
		Log::info('No habia categoria para comision');
		return null;
	}


}