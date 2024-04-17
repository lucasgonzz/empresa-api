<?php

namespace App\Http\Controllers\Helpers;

class SaleModificationsHelper {

    static function get_estado($sale) {
    	$estado = 'ninguno';
    	if ($sale->to_check) {
    		$estado = 'Para chequear';
    	} else if ($sale->checked) {
    		$estado = 'Chequeada';
    	} else if ($sale->confirmed) {
    		$estado = 'Confirmada';
    	}
    	return $estado;
    }


}