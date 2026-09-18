<?php

namespace App\Http\Controllers\Helpers\currentAcount;

use App\Http\Controllers\Helpers\CurrentAcountHelper;
use App\Http\Controllers\Helpers\CurrentAcountPagoHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Models\CurrentAcount;
use Illuminate\Support\Facades\Log;

/**
 * Alta de un pago de cuenta corriente (cobro a un cliente o pago a un proveedor), extraída de
 * CurrentAcountController::pago() en la misión asistente-ia-acciones (15/9/2026).
 *
 * Existe porque ahora hay DOS caminos que registran pagos: la pantalla (`POST api/current-acount/pago`)
 * y el asistente de IA, que ejecuta el pago cuando la persona confirma la tarjeta. Si el asistente
 * armara un `Request` falso contra el controller caería en la clase "el store() que lee un
 * subconjunto del request y el llamador que le manda el resto" (APRENDER_NO_PARCHEAR.md, 5/9/2026):
 * con un array de claves explícitas (CLAVES), lo que el alta lee está a la vista y cada llamador arma
 * exactamente eso. Un solo camino para la plata.
 *
 * 🔴 ES UNA MUDANZA, NO UN ARREGLO. El cuerpo de registrar() es el de pago() desde el
 * `CurrentAcount::create` hasta `pagar_cuota`, en el MISMO orden y con las MISMAS escrituras. No hace
 * lo que el controller hace alrededor, y cada cosa tiene su porqué:
 *   - No prevalida las cajas sin apertura: cada llamador lo hace ANTES, para poder responder 422 sin
 *     haber escrito nada (ver CurrentAcountCajaHelper::cajas_sin_apertura_en_payload()).
 *   - No abre transacción: la pantalla sigue SIN transacción (hallazgo 5 del informe del 21/8/2026,
 *     fuera de alcance), y el asistente llama a registrar() adentro de la suya.
 *   - No manda la notificación: eso es del HTTP de la pantalla, y adentro de la transacción del
 *     asistente un aviso que falla voltearía un pago que ya quedó bien.
 */
class CurrentAcountPagoAltaHelper {

    /**
     * Las 12 claves que pago() le leía al request. Toda clave que no vino vale null, igual que
     * `$request->clave`.
     *
     * @var array<int,string>
     */
    const CLAVES = [
        'credit_account_id',
        'model_name',
        'model_id',
        'current_acount_payment_methods',
        'haber',
        'description',
        'numero_orden_de_compra',
        'is_provisorio',
        'current_date',
        'created_at',
        'to_pay',
        'payment_plan_cuota',
    ];

    /**
     * Registra el pago, adjunta sus métodos de pago (con sus movimientos de caja), recalcula el
     * saldo, imputa contra los débitos pendientes y, si vino de una cuota, la marca.
     *
     * @param  array  $datos  Las claves de CLAVES. `current_acount_payment_methods` con la forma que
     *                        produce MultiPaymentMethods en la SPA.
     * @return \App\Models\CurrentAcount  El pago recién creado, con los atributos que quedaron
     *                                    guardados (es lo que la pantalla devuelve bajo `current_acount`).
     */
    static function registrar(array $datos) {

        /*
         * 🔴 Los helpers viejos que reciben el pedido (CurrentAcountHelper::getCreatedAt(),
         * CurrentAcountCuotaHelper::get_to_pay_id() y pagar_cuota()) lo leen como objeto
         * (`$request->current_date`, `isset($request->payment_plan_cuota)`). Por eso se les pasa un
         * objeto con las 12 claves SIEMPRE presentes: con una clave ausente, un `->clave` suelto
         * sería un "Undefined property" donde antes el Request devolvía null.
         */
        $pedido = self::pedido($datos);

        $pago = CurrentAcount::create([
            'haber'                             => self::get_haber($pedido),
            'description'                       => $pedido->description,
            'numero_orden_de_compra'            => $pedido->numero_orden_de_compra,
            'credit_account_id'                 => $pedido->credit_account_id,
            'is_provisorio'                     => $pedido->is_provisorio,
            'status'                            => 'pago_from_client',
            'user_id'                           => UserHelper::userId(),
            'num_receipt'                       => CurrentAcountHelper::getNumReceipt(),
            /*
             * to_pay explícito del pedido, o —si el pago viene de una cuota de un plan de
             * pago— el débito de la venta del plan (tanda correctivos 2408, ítem 13: regla
             * de Lucas, el pago de una cuota se imputa a la venta del plan y no al
             * comprobante más viejo). Ver CurrentAcountCuotaHelper::get_to_pay_id().
             */
            'to_pay_id'                         => CurrentAcountCuotaHelper::get_to_pay_id($pedido),
            'client_id'                         => $pedido->model_name == 'client' ? $pedido->model_id : null,
            'provider_id'                       => $pedido->model_name == 'provider' ? $pedido->model_id : null,
            'created_at'                        => CurrentAcountHelper::getCreatedAt($pedido),
            'employee_id'                       => UserHelper::userId(false),
        ]);

        $pago->detalle = 'Pago N°'.$pago->num_receipt;
        $pago->save();

        CurrentAcountPagoHelper::attachPaymentMethods($pago, $pedido->current_acount_payment_methods, $pedido->model_name);

        if (!$pago->is_provisorio) {

            /*
             * Calcular el saldo que genera este pago y persistirlo: se resta el haber al saldo
             * previo de la cuenta corriente.
             *
             * ⚠️ Rareza heredada y conservada a propósito: el `create` de arriba guarda como haber
             * la SUMA de las filas de métodos de pago (get_haber()), pero el saldo resta
             * `$pedido->haber`. La pantalla manda los dos iguales (el total del modal sale de la
             * misma suma) y el asistente también; corregirlo acá sería cambiar el comportamiento de
             * la pantalla adentro de una mudanza.
             */
            $saldo = CurrentAcountHelper::getSaldo($pedido->credit_account_id, $pago) - (float)$pedido->haber;
            $pago->saldo = $saldo;
            $pago->save();
            // Sincroniza saldo de la cuenta corriente y saldo por moneda en el model asociado.
            CurrentAcountHelper::update_credit_account_saldo($pedido->credit_account_id);

            /*
             * 🔴 LAS DOS RAMAS SALDAN EL MISMO DÉBITO, ASÍ QUE LAS DOS TIENEN QUE DEJAR TODO
             *    LO QUE DEPENDE DE QUE UN DÉBITO QUEDE SALDADO.
             *
             * `current_date` NO es una bandera de negocio: es una optimización. Con fecha pasada
             * hay que recalcular la cuenta entera porque el pago se mete en el medio del orden
             * cronológico; con la fecha de hoy alcanza con imputar el pago nuevo contra los
             * débitos pendientes. El hecho económico es el mismo.
             *
             * Y el default de la SPA es `current_date = 1`, o sea que la rama de abajo es EL
             * COBRO DE TODOS LOS DÍAS, no el caso raro. Cualquier efecto que se enganche a "el
             * débito quedó pagado" y viva solo del lado del recálculo completo va a andar en la
             * excepción y fallar en la regla — es exactamente lo que pasó con los puntos para
             * clientes hasta el 22/8/2026.
             *
             * Por eso acá NO hay ninguna llamada al módulo de puntos, ni la tiene que haber:
             * el enganche está al final de `CurrentAcountPagoHelper::init()`, que es el único
             * método por el que pasan las DOS ramas (check_saldos_y_pagos() también termina
             * llamándolo, una vez por pago). Si mañana aparece otro efecto de ese tipo, el lugar
             * es ése y no una copia en cada rama de este if.
             */
            if (!$pedido->current_date) {
                // Pago con fecha pasada: el recálculo completo ya se encarga
                // de recalcular saldos e imputar todos los pagos (incluyendo este)
                Log::info('Chequeando cuenta corriente entera');
                CurrentAcountHelper::check_saldos_y_pagos($pedido->credit_account_id);
            } else {
                // Pago con fecha actual: solo imputar el nuevo pago a los débitos pendientes
                // No hace falta recalcular toda la cuenta corriente
                Log::info('NO se chequeo cuenta corriente entera');
                $pago_helper = new CurrentAcountPagoHelper($pedido->credit_account_id, $pedido->model_name, $pedido->model_id, $pago);
                $pago_helper->init();
            }


            CurrentAcountCuotaHelper::pagar_cuota($pago, $pedido);
        }

        return $pago;
    }

    /**
     * Total del pago: la suma de las filas de métodos de pago, tomando el monto cotizado cuando la
     * fila vino en otra moneda. Mudado tal cual desde CurrentAcountController::get_haber().
     *
     * @param  object  $pedido  Objeto armado por pedido().
     * @return float|int
     */
    static function get_haber($pedido) {
        $total = 0;
        foreach ($pedido->current_acount_payment_methods as $payment_method) {

            if (
                isset($payment_method['amount_cotizado'])
                && !is_null($payment_method['amount_cotizado'])
                && $payment_method['amount_cotizado'] != ''
                && (float)$payment_method['amount_cotizado'] > 0
            ) {
                $haber = (float)$payment_method['amount_cotizado'];
            } else {

                $haber = (float)$payment_method['amount'];
            }

            $total += $haber;
        }
        return $total;
    }

    /**
     * Objeto con las 12 claves siempre presentes (null las que no vinieron), que es la forma en que
     * los helpers viejos leen el pedido.
     *
     * @param  array  $datos
     * @return \stdClass
     */
    static function pedido(array $datos) {

        $pedido = new \stdClass();

        foreach (self::CLAVES as $clave) {

            $pedido->{$clave} = array_key_exists($clave, $datos) ? $datos[$clave] : null;
        }

        return $pedido;
    }
}
