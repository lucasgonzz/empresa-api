<?php

namespace App\Http\Controllers\Helpers\sale;

class SalePdfHelper {
	
	static function get_afip_information($sale, $user) {
		if (!is_null($sale->afip_information) && !is_null($sale->afip_ticket)) {
			if ($user->info_afip_del_primer_punto_de_venta) {
				return $user->afip_information;
			}
			return $sale->afip_information;
		}
		return null;
	}

}