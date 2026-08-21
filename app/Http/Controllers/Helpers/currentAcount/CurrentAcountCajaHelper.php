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
	 * Se midio el 21/8/2026 en el slot s1: con una caja `abierta = 0` que ya habia tenido apertura,
	 * el pago devuelve 201 y crea el movimiento. Validar por `abierta` habria rechazado ese pago con
	 * un 422 y roto a todo comercio que cobra despues del cierre.
	 *
	 * Existe aparte de `DeleteCajaCompensacionHelper::verificar_cajas_abiertas()` por dos motivos:
	 * aquel recorre la relacion ya attachada (objetos con `->pivot`) y este el array crudo del
	 * request, que es lo unico que hay antes de guardar; y aquel valida apertura vigente porque va a
	 * MOVER plata en el momento, mientras que este solo necesita que exista donde registrarla.
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

	/**
	 * @param float $pago_amount Monto del pago/cobro que impacta caja.
	 * @param int $caja_id Caja donde impacta.
	 * @param string $model_name 'client' (cobro) o 'provider' (pago a proveedor).
	 * @param \App\Models\CurrentAcount $pago Movimiento de cuenta corriente ya creado.
	 * @param string|null $notas
	 * @param int|null $current_acount_payment_method_id Grupo 223 · Prompt 02: método de pago con el
	 *        que entró la plata, para resolver la cascada de liquidación/comisión. Último parámetro
	 *        opcional para no romper a los llamadores existentes (ChequeController no lo manda).
	 */
	static function guardar_pago($pago_amount, $caja_id, $model_name, $pago, $notas = null, $current_acount_payment_method_id = null) {

        $ingreso = null;
        $egreso = null;
        $concepto_movimiento_caja_id = null;

        // Grupo 223 · Prompt 02: solo un cobro a cliente con método de pago informado aplica
        // liquidación/comisión. Un pago a proveedor es un egreso y nunca la tuvo. El tercer
        // llamador (ChequeController) no manda `$current_acount_payment_method_id`, así que un
        // cheque nunca liquida por esta vía aunque el cobro sea a cliente.
        $aplica_liquidacion = ($model_name == 'client') && !is_null($current_acount_payment_method_id);

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

            'aplica_liquidacion'                        => $aplica_liquidacion,
            'current_acount_payment_method_id'          => $current_acount_payment_method_id,
        ];

        $helper = new MovimientoCajaHelper();
        $movimiento_caja = $helper->crear_movimiento($data);
	}
	
}