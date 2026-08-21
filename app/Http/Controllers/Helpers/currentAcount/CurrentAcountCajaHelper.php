<?php

namespace App\Http\Controllers\Helpers\currentAcount;

use App\Http\Controllers\Helpers\caja\MovimientoCajaHelper;
use App\Models\AperturaCaja;
use App\Models\Caja;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class CurrentAcountCajaHelper {

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
	/**
	 * Nombres de las cajas CERRADAS entre los metodos de pago que vienen en el request, para poder
	 * frenar el pago ANTES de crear nada.
	 *
	 * Existe aparte de DeleteCajaCompensacionHelper::verificar_cajas_abiertas() porque aquel
	 * recorre la relacion ya attachada (objetos con ->pivot) y este recorre el array crudo del
	 * request, que es lo unico que hay disponible antes de guardar. El criterio es el mismo:
	 * sin caja_id (null o 0) no hay impacto en caja y no se valida nada; una caja que no existe se
	 * saltea; el fallback del nombre es "Caja N° {num}".
	 *
	 * @param array|null $payment_methods Array crudo `current_acount_payment_methods` del request.
	 * @return array Nombres de las cajas cerradas, sin repetir.
	 */
	static function cajas_cerradas_en_payload($payment_methods) {

		$cerradas_por_id = [];

		if (!is_array($payment_methods)) {

			return [];
		}

		foreach ($payment_methods as $payment_method) {

			if (!isset($payment_method['caja_id']) || (int)$payment_method['caja_id'] === 0) {

				continue;
			}

			$caja = Caja::find($payment_method['caja_id']);

			if (is_null($caja)) {

				continue;
			}

			if ((int)$caja->abierta !== 1) {

				$cerradas_por_id[$caja->id] = $caja->name ? $caja->name : ('Caja N° '.$caja->num);
			}
		}

		return array_values($cerradas_por_id);
	}

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