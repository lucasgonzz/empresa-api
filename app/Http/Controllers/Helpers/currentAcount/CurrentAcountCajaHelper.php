<?php

namespace App\Http\Controllers\Helpers\currentAcount;

use App\Http\Controllers\Helpers\caja\MovimientoCajaHelper;
use App\Models\AperturaCaja;
use App\Models\Caja;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class CurrentAcountCajaHelper {

	static function guardar_pago($pago_amount, $caja_id, $model_name, $pago) {

        $ingreso = null;
        $egreso = null;
        $concepto_movimiento_caja_id = null;

        if ($model_name == 'client') {

            $concepto_movimiento_caja_id = 3;
            $ingreso = $pago_amount;
            $notas = $pago->client->name.'. Pago N° '.$pago->num_receipt;
        } else {

            $concepto_movimiento_caja_id = 4;
            $egreso = $pago_amount;
            $notas = $pago->provider->name.'. Pago N° '.$pago->num_receipt;
        }

        $data = [
            'concepto_movimiento_caja_id'   => $concepto_movimiento_caja_id,
            'ingreso'                       => $ingreso,
            'egreso'                        => $egreso,
            'notas'                         => $notas,
            'caja_id'                       => $caja_id,
            'current_acount_id'             => $pago->id,
        ];

        $helper = new MovimientoCajaHelper();
        $movimiento_caja = $helper->crear_movimiento($data);
	}
	
}