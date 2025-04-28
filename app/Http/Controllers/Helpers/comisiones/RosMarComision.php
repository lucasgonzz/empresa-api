<?php

namespace App\Http\Controllers\Helpers\comisiones;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\comisiones\ComisionesHelper;
use App\Http\Controllers\Helpers\comisiones\Helper;
use App\Models\SellerCommission;
use Illuminate\Support\Facades\Log;

class RosMarComision {
	
	public $sale;
	public $en_blanco;

	function __construct($sale) {

		$this->sale = $sale;

		if (!is_null($this->sale->afip_information_id)
			&& $this->sale->afip_information_id != 0) {

			$this->en_blanco = true;
		} else {
			$this->en_blanco = false;
		}

		$this->ct = new Controller();
	}

	function crear_comision() {

		$total = 0;

		foreach ($this->sale->articles as $article) {

			$total_article = $article->pivot->price * $article->pivot->amount;

			if (!is_null($this->sale->seller)
				&& !is_null($this->sale->seller->percentage_commission)) {

				Log::info('total_article de '.$total_article);

				Log::info('usando el percentage_commission de '.$this->sale->seller->percentage_commission);

			
				$comision = $total_article * (float)$this->sale->seller->percentage_commission / 100;

				Log::info('La comision es de '.$comision);

			} else if ($this->en_blanco) {

				$porcentaje = $article->provider->porcentaje_comision_blanco;

				$iva = 21;

				if (!is_null($article->iva)) {
					
					if ($article->iva->percentage == 'Exento'
						|| $article->iva->percentage == 'No Gravado') {

						$iva = 0;
					} else {

						$iva = (float)$article->iva->percentage;
					}
				}

				// Se calcula sobre el valor sin IVA
				$precio_sin_iva = $total_article / (($iva / 100) + 1);

				$comision = $precio_sin_iva * $porcentaje / 100;

			} else {

				$porcentaje = $article->provider->porcentaje_comision_negro;

				$comision = $total_article * $porcentaje / 100;

			}

			Log::info('Sumando '.$comision);

			$total += $comision;
			Log::info('total: '.$total);
		}

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