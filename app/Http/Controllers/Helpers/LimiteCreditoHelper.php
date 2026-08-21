<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\Helpers\CurrentAcountHelper;
use App\Http\Controllers\Helpers\SaleHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Models\Client;
use App\Models\CreditAccount;
use App\Models\Moneda;
use App\Models\Sale;

/**
 * Chequeo del límite de crédito del cliente al guardar una venta nueva (misión 160).
 *
 * No reescribe las reglas de "¿esta venta entra a la cuenta corriente?": arma una `Sale`
 * hipotética (sin guardarla) con los mismos datos del request y le pregunta a
 * `SaleHelper::va_a_volver_a_la_cuenta_corriente()`, que es la misma función que usa el flujo
 * real al guardar. Si mañana esa regla cambia, este chequeo cambia con ella.
 */
class LimiteCreditoHelper {

    /**
     * @param  \Illuminate\Http\Request  $request  Request del POST api/sale, sin tocar.
     * @return array|null  null = la venta puede guardarse. Array = cuerpo del 422.
     */
    static function validar_venta_nueva($request) {

        $client_id = $request->client_id;

        // Venta de mostrador: no hay cliente, no hay cuenta corriente que pueda excederse.
        if (is_null($client_id) || !$client_id) {
            return null;
        }

        // Venta "para chequear": SaleHelper::attachProperies() no llama a create_current_acount()
        // cuando to_check, así que esta venta no impacta el saldo al guardarse.
        if ($request->to_check) {
            return null;
        }

        $client = Client::find($client_id);

        // Cliente inexistente o borrado (SoftDeletes): mismo resultado que en el flujo real,
        // donde $sale->client también viene null.
        if (is_null($client)) {
            return null;
        }

        $moneda_id = !is_null($request->moneda_id) ? (int) $request->moneda_id : 1;

        // Sale hipotética, sin guardar: el objeto que las reglas ya saben leer, sin crear una
        // venta fantasma.
        $sale = new Sale([
            'client_id'                  => $client_id,
            'save_current_acount'        => $request->save_current_acount,
            'omitir_en_cuenta_corriente' => $request->omitir_en_cuenta_corriente,
            'to_check'                   => $request->to_check,
            'total'                      => $request->total,
            'moneda_id'                  => $moneda_id,
        ]);

        // Misma condición exacta que SaleHelper::check_guardad_cuenta_corriente_despues_de_facturar(),
        // llamando al mismo helper que ella llama. 🔴 No hacer $sale->save() acá: es hipotética.
        if (UserHelper::hasExtencion('guardad_cuenta_corriente_despues_de_facturar')
            && !SaleHelper::al_cliente_se_le_factura_en_el_acto($sale)) {

            $sale->save_current_acount = 0;
        }

        if (!SaleHelper::va_a_volver_a_la_cuenta_corriente($sale)) {
            return null;
        }

        // La credit_account de LA MISMA MONEDA de la venta: no comparar pesos contra dólares.
        $credit_account = CreditAccount::where('model_name', 'client')
                                        ->where('model_id', $client->id)
                                        ->where('moneda_id', $moneda_id)
                                        ->first();

        if (is_null($credit_account) || is_null($credit_account->limite_credito)) {
            return null;
        }

        // Saldo recalculado con el mismo helper que usa CurrentAcountFromSaleHelper para el
        // movimiento que va a persistir, no el denormalizado de credit_accounts.saldo.
        $saldo_actual     = (float) CurrentAcountHelper::getSaldo($credit_account->id);
        $total            = (float) $request->total;
        $saldo_resultante = $saldo_actual + $total;
        $limite           = (float) $credit_account->limite_credito;

        // Tolerancia de un centavo: mismo criterio que SaleController::makeAfipTicket() con el
        // tope del importe personalizado. Sin ella se rechazaría el caso más común, que es vender
        // exactamente hasta el límite.
        if ($saldo_resultante <= $limite + 0.01) {
            return null;
        }

        $moneda = Moneda::find($moneda_id);
        $moneda_name = !is_null($moneda) ? $moneda->name : 'Peso';

        $excedente = round($saldo_resultante - $limite, 2);

        return [
            'error_limite_credito' => true,
            'message'              => 'El cliente '.$client->name.' superaría su límite de crédito en '.$moneda_name.'. '.
                'Saldo actual: '.Self::formato($saldo_actual).'. '.
                'Total de esta venta: '.Self::formato($total).'. '.
                'Saldo resultante: '.Self::formato($saldo_resultante).'. '.
                'Límite: '.Self::formato($limite).'.',
            'limite_credito'        => [
                'client_id'         => $client->id,
                'client_name'       => $client->name,
                'credit_account_id' => $credit_account->id,
                'moneda_id'         => $moneda_id,
                'moneda_name'       => $moneda_name,
                'saldo_actual'      => round($saldo_actual, 2),
                'total_venta'       => round($total, 2),
                'saldo_resultante'  => round($saldo_resultante, 2),
                'limite_credito'    => round($limite, 2),
                'excedente'         => $excedente,
            ],
        ];
    }

    /**
     * @param  float|int|string  $numero
     * @return string
     */
    static function formato($numero) {
        return number_format((float) $numero, 2, ',', '.');
    }
}
