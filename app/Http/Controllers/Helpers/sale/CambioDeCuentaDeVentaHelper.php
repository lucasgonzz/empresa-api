<?php

namespace App\Http\Controllers\Helpers\sale;

use App\Http\Controllers\Helpers\CreditAccountHelper;
use App\Models\Client;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\PaymentPlan;
use App\Models\Sale;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * El CAMBIO DE CUENTA CORRIENTE de una venta que se edita: le cambian el cliente, la moneda, o las dos
 * cosas, y su débito se muda de una cuenta a otra (misión edicion-completa-ventas-presupuestos,
 * 8/10/2026).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  QUÉ HACE YA EL SISTEMA Y QUÉ AGREGA ESTE HELPER
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  La mudanza en sí ya existía: `SaleHelper::updateCurrentAcountsAndCommissions()` borra el débito de
 *  la cuenta vieja (`deleteCurrentAcountFromSale()`: libera los pagos dirigidos y las imputaciones), lo
 *  crea en la cuenta del cliente y la moneda que quedan (`CurrentAcountFromSaleHelper`) y recalcula
 *  ENTERA la cuenta de la que salió. La cuenta corriente está partida por moneda (`credit_accounts` por
 *  cliente + moneda), así que cambiar la moneda es el mismo movimiento que cambiar el cliente.
 *
 *  Lo que faltaba son los casos en los que esa mudanza deja la plata mal o revienta. Este helper los
 *  frena ANTES de abrir la transacción de `SaleController::update()` (son respuestas, no fallos que
 *  haya que revertir), y prepara la cuenta de destino adentro de la transacción:
 *
 *   1. El cliente nuevo NO EXISTE o es de OTRO COMERCIO → 422. Es una guarda de tenencia: el PUT
 *      asigna `client_id` a secas y la venta del comercio terminaba en la cuenta de un cliente ajeno.
 *   2. La venta tiene una NOTA DE CRÉDITO en la cuenta corriente → 422.
 *      `deleteCurrentAcountFromSale()` mueve SOLO el débito (la fila con `haber` null): la NC de la
 *      devolución se queda en la cuenta vieja. Resultado: el cliente viejo queda con un crédito por
 *      una devolución de una venta que ya no es suya, y el nuevo paga el total sin la devolución.
 *      Decisión de la Fase 5 por el lado seguro (8/10/2026); si Lucas quiere que la NC viaje con la
 *      venta, es otra misión.
 *   3. La venta está incluida en una FACTURACIÓN CONSOLIDADA → 422. La factura salió a nombre del
 *      cliente (y en la moneda) que la venta tenía: moverla deja la factura contando una venta que ya
 *      no es de ese cliente. No hay forma de sacar una venta de una consolidación desde la pantalla.
 *   4. El cliente que queda NO TIENE la cuenta corriente de la moneda que queda → se le crea, adentro
 *      de la transacción (si algo falla después, el rollback la deshace). Sin esto
 *      `CurrentAcountFromSaleHelper` usa la cuenta sin chequear null y la edición termina en un 500.
 *   5. La venta tiene un PLAN DE PAGO en cuotas → 422 (revisión independiente, 8/10/2026).
 *      `payment_plans` y `payment_plan_cuotas` guardan `client_id` y `sale_id` y no viajan con el
 *      débito. Medido: después de mudar la venta de A a B, pagar la cuota como la arma `PagarBtn.vue`
 *      dejaba un pago con `client_id` = A en la cuenta de B, y el siguiente
 *      `check_saldos_y_pagos()` de B (`reimputar_pagos()` filtra por `client_id`) devolvía el débito a
 *      `sin_pagar` con el pago restando igual en el saldo de B. Un plan de pago no se puede eliminar
 *      desde la pantalla (`payment-plan` solo tiene alta), así que el mensaje no ofrece salida.
 *   6. La venta mantiene en el PUT un COMBO que ya tenía y cambia la MONEDA → 422 (misma revisión).
 *      `ComboCostoDeVentaHelper::costo_para_renglon()` le deja a un combo que la venta ya tenía su
 *      costo CONGELADO de la base, sin mirar la moneda: pasada a otra moneda, la venta quedaba con el
 *      precio del combo convertido y el costo en la moneda vieja (la ganancia, absurda). Un combo
 *      NUEVO en esta edición se cotiza en la moneda que queda y no tiene el problema, así que sacar los
 *      combos en el mismo PUT que cambia la moneda sí se deja. Esta guarda no depende de la cuenta
 *      corriente: vale también para una venta de contado.
 *
 *   7. El PUT le CAMBIA EL CLIENTE a una venta que tiene débito en la cuenta corriente y en la misma
 *      edición la deja "Para checkear" (`to_check`) o chequeada (`checked`) → 422 (punto 7 de la
 *      revisión, 8/10/2026). Con esos flags `update()` no toca la cuenta corriente y el débito quedaba
 *      en la cuenta del cliente viejo con la venta a nombre del nuevo.
 *
 *  Todos los 422 tienen la misma forma: `message`, `cambio_de_cuenta_rechazado` = true y `motivo`.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  🔴 CUÁNDO SE MIRAN, Y POR QUÉ NO SIEMPRE
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  - La guarda 1 (tenencia) corre ante CUALQUIER cambio a otro cliente: la SPA solo ofrece clientes
 *    del comercio, así que a ella no le cambia nada.
 *  - La guarda 6 (combos) corre ante cualquier cambio de moneda, vaya o no la venta a la cuenta
 *    corriente: el costo del combo está mal en los dos casos.
 *  - Las guardas 2, 3 y 5 y la cuenta de destino (4) corren solo cuando el débito SE MUDA: la venta queda
 *    en la cuenta corriente después del PUT Y cambia el cliente o la moneda. No corren:
 *      · en una venta omitida de la cuenta corriente (de contado con cliente): es el único cambio de
 *        cliente que permitía la SPA anterior a esta misión, y no mueve nada de ninguna cuenta. Con
 *        las guardas ahí, la SPA vieja recibiría 422 nuevos que no sabe mostrar;
 *      · cuando la edición le SACA el cliente a la venta: el débito no se muda, se borra
 *        (`SaleHelper::sacar_de_la_cuenta_corriente()`), y ese camino quedó fuera de esta misión.
 *
 * PHP 7.4: sin match, str_contains, ?->, argumentos nombrados ni union types.
 */
class CambioDeCuentaDeVentaHelper
{
    /**
     * El cliente con el que la venta queda después del PUT, o null si queda sin cliente. Un 0 o un
     * vacío son "sin cliente", igual que los lee `SaleController::update()` (`if ($model->client_id)`).
     *
     * @param  \Illuminate\Http\Request  $request
     * @return int|null
     */
    static function client_id_que_queda($request)
    {
        if (is_numeric($request->client_id) && (int) $request->client_id > 0) {
            return (int) $request->client_id;
        }

        return null;
    }

    /**
     * ¿El PUT le pone a la venta OTRO cliente (no null)?
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Sale  $sale  La venta como está guardada.
     * @return bool
     */
    static function cambia_el_cliente($request, $sale)
    {
        $client_id_que_queda = self::client_id_que_queda($request);

        return !is_null($client_id_que_queda) && $client_id_que_queda != (int) $sale->client_id;
    }

    /**
     * ¿El PUT le cambia la moneda a la venta? Se comparan las dos normalizadas (NULL y 0 son pesos,
     * como en todo el sistema): una venta vieja con `moneda_id` NULL que recibe 1 no cambia de cuenta.
     *
     * @param  \App\Models\Sale  $sale
     * @param  int|null  $moneda_que_queda  La que resolvió `CotizacionDeVentaHelper`, o null si el PUT no
     *                                      habla de la moneda (no se toca).
     * @return bool
     */
    static function cambia_la_moneda($sale, $moneda_que_queda)
    {
        if (is_null($moneda_que_queda)) {
            return false;
        }

        return CotizacionDeVentaHelper::normalizar_moneda_id($sale->moneda_id) != (int) $moneda_que_queda;
    }

    /**
     * ¿La venta va a quedar en la cuenta corriente después del PUT? Son las mismas condiciones con las
     * que `SaleController::update()` decide llamar a `updateCurrentAcountsAndCommissions()` (cliente, ni
     * `to_check` ni `checked`, que se asignan a secas del request) y con las que
     * `SaleHelper::va_a_volver_a_la_cuenta_corriente()` decide recrear el débito (`save_current_acount`,
     * que `update()` nunca reasigna, y `omitir_en_cuenta_corriente`, que solo se pisa si viaja).
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Sale  $sale
     * @return bool
     */
    static function va_a_quedar_en_la_cuenta_corriente($request, $sale)
    {
        if (is_null(self::client_id_que_queda($request))) {
            return false;
        }

        if ($request->to_check || $request->checked) {
            return false;
        }

        $omitir_que_queda = $request->exists('omitir_en_cuenta_corriente')
                                ? $request->omitir_en_cuenta_corriente
                                : $sale->omitir_en_cuenta_corriente;

        return (bool) ($sale->save_current_acount && !$omitir_que_queda);
    }

    /**
     * ¿El débito de la venta se muda de cuenta? Queda en la cuenta corriente y cambia el cliente o la
     * moneda. Es lo que dispara las guardas de nota de crédito y de consolidación, y la creación de la
     * cuenta de destino.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Sale  $sale
     * @param  int|null  $moneda_que_queda
     * @return bool
     */
    static function el_debito_se_muda($request, $sale, $moneda_que_queda)
    {
        if (!self::va_a_quedar_en_la_cuenta_corriente($request, $sale)) {
            return false;
        }

        return self::cambia_el_cliente($request, $sale) || self::cambia_la_moneda($sale, $moneda_que_queda);
    }

    /**
     * El 422 de un cambio de cuenta que no se puede hacer, o null si la edición puede seguir. Solo lee.
     * Va ANTES de la transacción de `SaleController::update()`, junto con los otros 4xx tempranos.
     *
     * Cuerpo del 422: `message` (para mostrar), `cambio_de_cuenta_rechazado` = true y `motivo`
     * (`cliente_inexistente`, `cliente_ajeno`, `para_checkear`, `combos`, `nota_de_credito`,
     * `plan_de_pago` o `consolidada`).
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Sale  $sale  La venta como está guardada.
     * @param  int|null  $moneda_que_queda
     * @return array|null
     */
    static function validar($request, $sale, $moneda_que_queda)
    {
        if (self::cambia_el_cliente($request, $sale)) {

            // Client::find() no trae a los borrados: una venta no puede pasar a un cliente eliminado.
            $cliente_nuevo = Client::find(self::client_id_que_queda($request));

            if (is_null($cliente_nuevo)) {

                return self::rechazo('cliente_inexistente', 'El cliente elegido no existe o fue eliminado. Elegí otro y volvé a guardar la venta.');
            }

            if ((int) $cliente_nuevo->user_id != (int) $sale->user_id) {

                Log::warning('CambioDeCuentaDeVentaHelper: la venta '.$sale->id.' (dueño '.$sale->user_id.') se quiso pasar al cliente '.$cliente_nuevo->id.' del dueño '.$cliente_nuevo->user_id.'.');

                return self::rechazo('cliente_ajeno', 'El cliente elegido no es de este comercio.');
            }
        }

        /*
            Cambio de cliente + "Para checkear" en el mismo PUT (guarda 7 del bloque de la clase). Con
            `to_check` o `checked` en 1, `SaleController::update()` no llama ni a
            updateCurrentAcountsAndCommissions() ni a sacar_de_la_cuenta_corriente(): el débito que
            la venta YA tiene se quedaría en la cuenta del cliente viejo, con la venta a nombre del
            nuevo. Solo frena si hay débito (una venta que nunca entró a la cuenta corriente no deja
            nada colgado) y solo con cambio de cliente: pasarla a "Para checkear" sin cambiarlo es el
            comportamiento de siempre y no se toca acá.

            🔴 El mensaje NO ofrece hacerlo "al revés" (primero "Para checkear", después el cliente):
            en ese segundo PUT la venta sigue para checkear, el débito quedaría colgado igual, y esta
            misma guarda lo frena.
        */
        if (
            self::cambia_el_cliente($request, $sale)
            && ($request->to_check || $request->checked)
            && self::tiene_debito_en_la_cuenta_corriente($sale)
        ) {

            return self::rechazo(
                'para_checkear',
                'La venta está en la cuenta corriente de su cliente y en esta misma edición se le cambia el cliente y se la pasa a "Para checkear": el movimiento quedaría en la cuenta del cliente anterior. Primero guardá el cambio de cliente sin pasarla a "Para checkear", y después, en otra edición, pasala a "Para checkear".'
            );
        }

        /*
            Combos y cambio de moneda (guarda 6 del bloque de la clase). Va ANTES del corte por
            cuenta corriente a propósito: el costo congelado del combo queda en la moneda vieja también
            en una venta de contado.
        */
        if (self::cambia_la_moneda($sale, $moneda_que_queda) && self::mantiene_combos_que_ya_tenia($request, $sale)) {

            return self::rechazo(
                'combos',
                'La venta tiene combos y su costo quedó guardado en la moneda original, así que no se le puede cambiar la moneda con esos combos adentro. Sacá los combos de la venta para cambiarle la moneda, o eliminala y volvé a cargarla.'
            );
        }

        if (!self::el_debito_se_muda($request, $sale, $moneda_que_queda)) {
            return null;
        }

        /*
            La NC en la cuenta corriente es la fila con el `sale_id` de la venta y `haber` cargado
            (`CurrentAcountHelper::notaCredito()`: la de Devoluciones y la del panel de Vender). Mismo
            criterio, al revés, con el que `deleteCurrentAcountFromSale()` encuentra el débito
            (`haber` null): es exactamente lo que esa función NO mueve.
        */
        $tiene_nota_de_credito = CurrentAcount::where('sale_id', $sale->id)
                                            ->whereNotNull('haber')
                                            ->whereNotNull('credit_account_id')
                                            ->exists();

        if ($tiene_nota_de_credito) {

            return self::rechazo(
                'nota_de_credito',
                'La venta tiene una nota de crédito en la cuenta corriente, así que no se puede pasar a otro cliente ni a otra moneda: la devolución quedaría en la cuenta original y la venta entera en la nueva. Si hay que moverla, eliminala y volvé a cargarla.'
            );
        }

        /*
            Plan de pago en cuotas (guarda 5 del bloque de la clase): las cuotas guardan el cliente y
            la venta, y no se mudan con el débito. Basta con que exista el plan: sus cuotas, pagas o
            no, son de la venta.
        */
        if (PaymentPlan::where('sale_id', $sale->id)->exists()) {

            return self::rechazo(
                'plan_de_pago',
                'La venta tiene un plan de pago en cuotas: las cuotas están a nombre del cliente que tiene la venta y en su moneda, y no se mudan con ella. Por eso no se le puede cambiar el cliente ni la moneda, y un plan de pago no se puede eliminar desde el sistema.'
            );
        }

        /*
            Facturación consolidada (guarda 3). Solo con la venta contenedora viva, mismo criterio que
            `DeleteSaleHelper::motivo_por_el_que_no_se_puede_eliminar()`: si la contenedora ya se
            borró, su factura no sostiene esta venta. No hay forma de sacar una venta de una
            consolidación desde la pantalla, así que el mensaje no ofrece salida.
        */
        if (!empty($sale->consolidacion_facturacion_id)) {

            $contenedora = Sale::find($sale->consolidacion_facturacion_id);

            if (!is_null($contenedora)) {

                $num = !is_null($contenedora->num) ? $contenedora->num : $contenedora->id;

                return self::rechazo(
                    'consolidada',
                    'La venta está incluida en la facturación consolidada de la venta N° '.$num.': esa factura salió con el cliente y la moneda que tiene esta venta, y una venta no se puede sacar de una consolidación. Por eso no se le puede cambiar el cliente ni la moneda.'
                );
            }
        }

        return null;
    }

    /**
     * ¿La venta tiene hoy su débito en alguna cuenta corriente? Mismo criterio con el que
     * `SaleHelper::deleteCurrentAcountFromSale()` lo encuentra: la fila con su `sale_id` y `haber`
     * null.
     *
     * @param  \App\Models\Sale  $sale
     * @return bool
     */
    static function tiene_debito_en_la_cuenta_corriente($sale)
    {
        return CurrentAcount::where('sale_id', $sale->id)
                            ->whereNull('haber')
                            ->exists();
    }

    /**
     * ¿El PUT mantiene algún combo que la venta YA tenía guardado (`combo_sale`)? Un renglón es combo
     * con el mismo criterio que `SaleHelper::attachCombos()`: la clave `is_combo` presente. Un combo
     * nuevo en esta edición no cuenta: `ComboCostoDeVentaHelper::costo_para_renglon()` lo cotiza con
     * la moneda que queda; el congelado es solo el de los combos que ya estaban.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Sale  $sale
     * @return bool
     */
    static function mantiene_combos_que_ya_tenia($request, $sale)
    {
        $combos_guardados = DB::table('combo_sale')
                                ->where('sale_id', $sale->id)
                                ->pluck('combo_id')
                                ->map(function ($combo_id) {
                                    return (int) $combo_id;
                                })
                                ->all();

        if (count($combos_guardados) == 0 || !is_array($request->items)) {
            return false;
        }

        foreach ($request->items as $item) {

            if (is_array($item) && isset($item['is_combo']) && isset($item['id']) && in_array((int) $item['id'], $combos_guardados)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Si el débito se muda, deja creada la cuenta corriente del cliente que queda en la moneda que
     * queda (si no la tenía). Va ADENTRO de la transacción, después del candado de la cuenta
     * corriente: si la edición se rechaza más adelante (límite de crédito, canje de puntos), el
     * rollback la deshace.
     *
     * Reusa `CreditAccountHelper::crear_credit_accounts()`, que es idempotente y crea las dos monedas
     * que falten, como el alta de un cliente. Se mira antes si ya existe solo para no llenar el log
     * con su "Ya habia credit_account" en cada cambio de cuenta.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Sale  $sale
     * @param  int|null  $moneda_que_queda  null = la moneda no cambia (se usa la guardada).
     * @return void
     */
    static function asegurar_cuenta_de_destino($request, $sale, $moneda_que_queda)
    {
        if (!self::el_debito_se_muda($request, $sale, $moneda_que_queda)) {
            return;
        }

        $client_id = self::client_id_que_queda($request);

        $moneda_id = !is_null($moneda_que_queda)
                        ? (int) $moneda_que_queda
                        : CotizacionDeVentaHelper::normalizar_moneda_id($sale->moneda_id);

        $existe = CreditAccount::where('model_name', 'client')
                                ->where('model_id', $client_id)
                                ->where('moneda_id', $moneda_id)
                                ->exists();

        if ($existe) {
            return;
        }

        Log::info('CambioDeCuentaDeVentaHelper: el cliente '.$client_id.' no tenía cuenta corriente en la moneda '.$moneda_id.'; se le crea para recibir la venta '.$sale->id.'.');

        CreditAccountHelper::crear_credit_accounts('client', $client_id, $sale->user_id);
    }

    /**
     * El cuerpo del 422 de un cambio de cuenta rechazado.
     *
     * @param  string  $motivo
     * @param  string  $mensaje
     * @return array
     */
    protected static function rechazo($motivo, $mensaje)
    {
        return [
            'message'                    => $mensaje,
            'cambio_de_cuenta_rechazado' => true,
            'motivo'                     => $motivo,
        ];
    }
}
