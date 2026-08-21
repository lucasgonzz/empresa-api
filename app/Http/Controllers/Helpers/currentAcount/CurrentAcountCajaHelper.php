<?php

namespace App\Http\Controllers\Helpers\currentAcount;

use App\Http\Controllers\Helpers\caja\MovimientoCajaHelper;
use App\Models\AperturaCaja;
use App\Models\Caja;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class CurrentAcountCajaHelper {

	/**
	 * Nombres de las cajas destino que NUNCA se abrieron, entre los metodos de pago que vienen en el
	 * request, para poder frenar el pago ANTES de crear nada.
	 *
	 * 🔴 El criterio es "no tiene ninguna AperturaCaja", NO "esta cerrada". Son cosas distintas y
	 * confundirlas rompe un caso legitimo:
	 *
	 *   - Caja cerrada PERO con aperturas previas (el comercio que abre a la manana y cierra a la
	 *     noche): el movimiento se cuelga de la ultima apertura y el pago entra sin problema. Es el
	 *     comportamiento de hoy en todos los flujos -ventas incluidas- y no se toca.
	 *   - Caja sin NINGUNA apertura: `MovimientoCajaHelper::get_current_aperutra_caja()` no tiene de
	 *     donde colgar el movimiento y revienta. Ese es el unico caso que hay que frenar.
	 *
	 * Se midio el 21/8/2026: con una caja `abierta = 0` que ya habia tenido apertura, el pago
	 * devuelve 201 y crea el movimiento. Validar por `abierta` habria rechazado ese pago con un 422
	 * y roto a todo comercio que cobra despues del cierre.
	 *
	 * Una fila sin monto se saltea, igual que hace `PaymentMethodHelper::attach_payment_methods()`:
	 * nunca se adjunta ni genera movimiento, asi que no puede frenar el pago (el caso real es el
	 * usuario que agrega un metodo de pago de mas y lo deja vacio).
	 *
	 * @param array|null $payment_methods Array crudo `current_acount_payment_methods` del request.
	 * @return array Nombres de las cajas sin apertura, sin repetir.
	 */
	static function cajas_sin_apertura_en_payload($payment_methods) {

		$sin_apertura_por_id = [];

		if (!is_array($payment_methods)) {

			return [];
		}

		foreach ($payment_methods as $payment_method) {

			// Sin monto no se adjunta el metodo y no hay movimiento de caja que crear.
			if (!isset($payment_method['amount'])
				|| is_null($payment_method['amount'])
				|| $payment_method['amount'] === ''
				|| (float)$payment_method['amount'] <= 0) {

				continue;
			}

			if (!isset($payment_method['caja_id']) || (int)$payment_method['caja_id'] === 0) {

				continue;
			}

			$caja = Caja::find($payment_method['caja_id']);

			if (is_null($caja)) {

				continue;
			}

			if (!AperturaCaja::where('caja_id', $caja->id)->exists()) {

				$sin_apertura_por_id[$caja->id] = $caja->name ? $caja->name : ('Caja N° '.$caja->num);
			}
		}

		return array_values($sin_apertura_por_id);
	}

	static function guardar_pago($pago_amount, $caja_id, $model_name, $pago, $notas = null) {

        $ingreso = null;
        $egreso = null;
        $concepto_movimiento_caja_id = null;

        if ($model_name == 'client') {

            $concepto_movimiento_caja_id = 3;
            $ingreso = $pago_amount;

            if (!$notas && $pago) {
                $notas = $pago->client->name.'. Pago N° '.$pago->num_receipt;
            }
        } else {

            $concepto_movimiento_caja_id = 4;
            $egreso = $pago_amount;
            if (!$notas && $pago) {
                $notas = $pago->provider->name.'. Pago N° '.$pago->num_receipt;
            }
        }

        $data = [
            'concepto_movimiento_caja_id'   => $concepto_movimiento_caja_id,
            'ingreso'                       => $ingreso,
            'egreso'                        => $egreso,
            'notas'                         => $notas,
            'caja_id'                       => $caja_id,
            // 'current_acount_id'             => $pago->id,
        ];

        $helper = new MovimientoCajaHelper();
        $movimiento_caja = $helper->crear_movimiento($data);
	}
	
}