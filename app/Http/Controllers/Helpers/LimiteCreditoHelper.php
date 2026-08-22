<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\Helpers\CurrentAcountHelper;
use App\Http\Controllers\Helpers\SaleHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Models\Client;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\Moneda;
use App\Models\Sale;

/**
 * Chequeo del límite de crédito del cliente al guardar o actualizar una venta (misión 160).
 *
 * No reescribe las reglas de "¿esta venta entra a la cuenta corriente?": le pregunta a
 * `SaleHelper::va_a_volver_a_la_cuenta_corriente()`, que es la misma función que usa el flujo
 * real (tanto al crear como al actualizar). Si mañana esa regla cambia, este chequeo cambia con
 * ella.
 *
 * Tres entradas, una por cada lugar donde una venta empieza a impactar la cuenta corriente:
 *  - `validar_venta_nueva()` — `SaleController::store()`, ANTES de `DB::beginTransaction()`.
 *  - `validar_venta_actualizada()` — `SaleController::update()`, DENTRO de la transacción ya
 *    abierta, justo antes de `SaleHelper::updateCurrentAcountsAndCommissions()`.
 *  - `validar_pedido_confirmado()` — `OrderController::updateStatus()`/`update()` (prompt 610),
 *    para la venta que nace de confirmar un pedido de la tienda propia. 🔴 Esta última es la
 *    única de las tres cuyo 422 es **salteable**: ver su docblock.
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

        // 🔴 No hacer $sale->save() acá: es hipotética. Ver aplicar_bandera_facturar_primero().
        Self::aplicar_bandera_facturar_primero($sale);

        if (!SaleHelper::va_a_volver_a_la_cuenta_corriente($sale)) {
            return null;
        }

        // La credit_account de LA MISMA MONEDA de la venta: no comparar pesos contra dólares.
        $credit_account = Self::buscar_credit_account_con_limite($client->id, $moneda_id);

        if (is_null($credit_account)) {
            return null;
        }

        // Saldo recalculado con el mismo helper que usa CurrentAcountFromSaleHelper para el
        // movimiento que va a persistir, no el denormalizado de credit_accounts.saldo.
        $saldo_actual = (float) CurrentAcountHelper::getSaldo($credit_account->id);

        return Self::comparar_contra_limite($client, $credit_account, $moneda_id, $saldo_actual, (float) $request->total);
    }

    /**
     * Mismo chequeo que validar_venta_nueva(), pero para una venta que YA existe y se está
     * actualizando (`SaleController::update()`).
     *
     * `SaleHelper::updateCurrentAcountsAndCommissions()` SIEMPRE borra el movimiento de cuenta
     * corriente de esta venta (si tenía uno) y lo vuelve a crear si corresponde — el mismo
     * mecanismo cubre tanto "una venta to_check que se confirma y recién ahora entra a la cuenta
     * corriente" como "una venta que ya estaba y le suben el total". Por eso acá hay que restar el
     * movimiento actual del saldo antes de sumar el total nuevo: si no, un total que NO cambió se
     * compararía contra un saldo que ya lo incluye, y el sistema se rechazaría a sí mismo.
     *
     * @param  \App\Models\Sale  $sale  Venta ya guardada y refrescada (el $model de
     *                                  SaleController::update(), después del Sale::find() que
     *                                  sigue al segundo $model->save()).
     * @return array|null  null = la actualización puede seguir. Array = cuerpo del 422.
     */
    static function validar_venta_actualizada($sale) {

        // Mismo criterio que SaleController::update() para decidir si llama a
        // SaleHelper::updateCurrentAcountsAndCommissions(): sin cliente, o con to_check/checked,
        // no hay cuenta corriente que se pueda excender en esta actualización.
        if (is_null($sale->client_id) || !$sale->client_id) {
            return null;
        }

        if ($sale->to_check || $sale->checked) {
            return null;
        }

        $client = Client::find($sale->client_id);

        if (is_null($client)) {
            return null;
        }

        /*
            🔴 A propósito NO se llama acá a aplicar_bandera_facturar_primero() (a diferencia de
            validar_venta_nueva()).

            Esa función fuerza save_current_acount = 0 replicando lo que
            SaleHelper::check_guardad_cuenta_corriente_despues_de_facturar() hace en el momento de
            CREAR la venta — necesario en validar_venta_nueva() porque ahí se arma una Sale
            hipotética desde cero, que nunca pasó por ese forzado real.

            Acá $sale es la venta YA PERSISTIDA (SaleController::update() la vuelve a leer con
            Sale::find() antes de este chequeo). Su save_current_acount es el valor real que quedó
            grabado cuando se CREÓ (update() nunca reasigna esa columna — verificado, no aparece en
            SaleController::update()), y es exactamente ESE valor el que
            va_a_volver_a_la_cuenta_corriente() usa para decidir si
            updateCurrentAcountsAndCommissions() recrea el movimiento. Forzarlo de nuevo acá según
            el estado ACTUAL de la extensión podría desalinearse de lo que la actualización real
            hace si la extensión (o la bandera del cliente) cambió después de que la venta se creó:
            dejaría pasar sin chequeo una venta que en los hechos sí vuelve a la cuenta corriente
            con el total nuevo. Por eso se usa directamente el valor persistido.
        */
        if (!SaleHelper::va_a_volver_a_la_cuenta_corriente($sale)) {
            return null;
        }

        // update() nunca reasigna moneda_id (no está entre los campos que reescribe): en una venta
        // ya persistida siempre viene seteado, pero se normaliza igual por las dudas.
        $moneda_id = !is_null($sale->moneda_id) ? (int) $sale->moneda_id : 1;

        $credit_account = Self::buscar_credit_account_con_limite($client->id, $moneda_id);

        if (is_null($credit_account)) {
            return null;
        }

        $saldo_actual = (float) CurrentAcountHelper::getSaldo($credit_account->id);

        // El movimiento que esta MISMA venta ya tiene en esta cuenta (si tiene uno): hay que
        // restarlo antes de sumar el total nuevo, porque updateCurrentAcountsAndCommissions() lo
        // va a borrar y recrear. whereNull('haber') lo distingue de un pago/cobro imputado a esta
        // venta (mismo filtro que usa SaleHelper::deleteCurrentAcountFromSale()).
        $movimiento_actual = CurrentAcount::where('sale_id', $sale->id)
                                        ->where('credit_account_id', $credit_account->id)
                                        ->whereNull('haber')
                                        ->first();

        $saldo_sin_este_movimiento = $saldo_actual - (!is_null($movimiento_actual) ? (float) $movimiento_actual->debe : 0);

        return Self::comparar_contra_limite($client, $credit_account, $moneda_id, $saldo_sin_este_movimiento, (float) $sale->total);
    }

    /**
     * Chequeo del límite de crédito en el camino del PEDIDO CONFIRMADO (prompt 610).
     *
     * Hasta este prompt, el límite sólo se miraba en SaleController::store()/update(). Cuando se
     * confirma un pedido de la tienda propia, la venta la crea CreateSaleOrderHelper sin pasar
     * por SaleController: un cliente al tope de su límite no podía comprar en el mostrador y sí
     * desde la tienda.
     *
     * 🔴 A diferencia del mostrador, este aviso es SALTEABLE (decisión de Lucas, 22/8/2026): el
     * que confirma es el dueño mirando una lista de pedidos, no un vendedor con el cliente
     * adelante, y frenarlo en seco en medio de una tanda es peor que el problema. Quien llama
     * decide si respeta el 422 o lo saltea con `ignorar_limite_credito` — ver
     * OrderController::chequear_limite_credito_del_pedido().
     *
     * Recibe los valores YA derivados en vez de derivarlos de nuevo: son los mismos que
     * CreateSaleOrderHelper::get_client_id() / get_to_check() le van a dar a createSale() un
     * instante después. Si esto los recalculara por su cuenta, podrían desalinearse.
     *
     * 🔴 NO se llama a aplicar_bandera_facturar_primero() acá, a diferencia de
     * validar_venta_nueva(). Esa función replica lo que SaleController::store() hace en la línea
     * 244 llamando a SaleHelper::check_guardad_cuenta_corriente_despues_de_facturar(). El camino
     * del pedido NO pasa por ahí: CreateSaleOrderHelper::createSale() hardcodea
     * save_current_acount => 1 y nadie se lo baja después. Aplicar la bandera acá dejaría pasar
     * sin chequeo una venta que en los hechos sí entra a la cuenta corriente.
     *
     * @param  int|null  $client_id  De CreateSaleOrderHelper::get_client_id().
     * @param  bool      $to_check   De CreateSaleOrderHelper::get_to_check().
     * @param  float     $total      Total del pedido, que es el que createSale() va a copiar.
     * @return array|null  null = el pedido se puede confirmar. Array = cuerpo del 422.
     */
    static function validar_pedido_confirmado($client_id, $to_check, $total) {

        // Pedido de invitado, de Tienda Nube o de Mercado Libre: la venta nace sin cliente, así
        // que no hay cuenta corriente que pueda excederse.
        if (is_null($client_id) || !$client_id) {
            return null;
        }

        // Venta "para chequear" (extensión check_sales): SaleHelper::attachProperies() no llama a
        // create_current_acount() cuando to_check, así que esta venta no mueve el saldo al
        // guardarse. Mismo corte que validar_venta_nueva().
        if ($to_check) {
            return null;
        }

        $client = Client::find($client_id);

        if (is_null($client)) {
            return null;
        }

        /*
            No se pregunta por SaleHelper::va_a_volver_a_la_cuenta_corriente() como en las otras
            dos entradas: en este camino sería una tautología. CreateSaleOrderHelper::createSale()
            escribe save_current_acount => 1 literal y nunca asigna omitir_en_cuenta_corriente
            (default 0 en la migración de sales), así que esa función siempre daría true. Los
            únicos cortes reales son los dos de arriba.
        */

        // moneda_id fijo en 1 porque createSale() lo hardcodea así. 🔴 Si esa línea alguna vez
        // deja de ser fija, esta tiene que acompañarla o el límite se mediría contra la cuenta
        // corriente equivocada.
        $moneda_id = 1;

        $credit_account = Self::buscar_credit_account_con_limite($client->id, $moneda_id);

        if (is_null($credit_account)) {
            return null;
        }

        $saldo_actual = (float) CurrentAcountHelper::getSaldo($credit_account->id);

        return Self::comparar_contra_limite($client, $credit_account, $moneda_id, $saldo_actual, (float) $total);
    }

    /**
     * La credit_account del cliente en ESA moneda, sólo si tiene límite cargado.
     *
     * Devolver null cuando `limite_credito` es null no es un atajo: null = sin límite = el
     * comportamiento de antes de la misión 160, que es el caso de la enorme mayoría de los
     * clientes.
     *
     * @param  int  $client_id
     * @param  int  $moneda_id
     * @return \App\Models\CreditAccount|null
     */
    private static function buscar_credit_account_con_limite($client_id, $moneda_id) {

        $credit_account = CreditAccount::where('model_name', 'client')
                                        ->where('model_id', $client_id)
                                        ->where('moneda_id', $moneda_id)
                                        ->first();

        if (is_null($credit_account) || is_null($credit_account->limite_credito)) {
            return null;
        }

        return $credit_account;
    }

    /**
     * La comparación en sí, compartida por las tres entradas.
     *
     * @param  \App\Models\Client         $client
     * @param  \App\Models\CreditAccount  $credit_account
     * @param  int    $moneda_id
     * @param  float  $saldo_base  Saldo contra el que se suma el total (ya neteado de lo que
     *                             corresponda: ver validar_venta_actualizada()).
     * @param  float  $total
     * @return array|null
     */
    private static function comparar_contra_limite($client, $credit_account, $moneda_id, $saldo_base, $total) {

        $saldo_resultante = $saldo_base + $total;
        $limite           = (float) $credit_account->limite_credito;

        // Tolerancia de un centavo: mismo criterio que SaleController::makeAfipTicket() con el
        // tope del importe personalizado. Sin ella se rechazaría el caso más común, que es vender
        // exactamente hasta el límite.
        if ($saldo_resultante <= $limite + 0.01) {
            return null;
        }

        return Self::armar_respuesta_422(
            $client->id,
            $client->name,
            $credit_account->id,
            $moneda_id,
            $saldo_base,
            $total,
            $saldo_resultante,
            $limite
        );
    }

    /**
     * Fuerza save_current_acount a 0 en una Sale HIPOTÉTICA (sin persistir) cuando la extensión
     * "guardad_cuenta_corriente_despues_de_facturar" está activa y el cliente no tiene la bandera
     * de saltearla. Replica la condición exacta de
     * SaleHelper::check_guardad_cuenta_corriente_despues_de_facturar() (llamando al mismo helper
     * que ella llama), sin el $sale->save() — acá $sale nunca se persiste.
     *
     * Sólo la usa validar_venta_nueva(). Ver el comentario dentro de validar_venta_actualizada()
     * sobre por qué esa otra función NO la necesita (y no debe usarla).
     *
     * @param  \App\Models\Sale  $sale  Sale hipotética, mutada in-place.
     * @return void
     */
    private static function aplicar_bandera_facturar_primero($sale) {

        if (UserHelper::hasExtencion('guardad_cuenta_corriente_despues_de_facturar')
            && !SaleHelper::al_cliente_se_le_factura_en_el_acto($sale)) {

            $sale->save_current_acount = 0;
        }
    }

    /**
     * Arma el cuerpo del 422, compartido por validar_venta_nueva() y validar_venta_actualizada().
     *
     * @param  int     $client_id
     * @param  string  $client_name
     * @param  int     $credit_account_id
     * @param  int     $moneda_id
     * @param  float   $saldo_actual
     * @param  float   $total
     * @param  float   $saldo_resultante
     * @param  float   $limite
     * @return array
     */
    private static function armar_respuesta_422($client_id, $client_name, $credit_account_id, $moneda_id, $saldo_actual, $total, $saldo_resultante, $limite) {

        $moneda = Moneda::find($moneda_id);
        $moneda_name = !is_null($moneda) ? $moneda->name : 'Peso';

        $excedente = round($saldo_resultante - $limite, 2);

        return [
            'error_limite_credito' => true,
            'message'              => 'El cliente '.$client_name.' superaría su límite de crédito en '.$moneda_name.'. '.
                'Saldo actual: '.Self::formato($saldo_actual).'. '.
                'Total de esta venta: '.Self::formato($total).'. '.
                'Saldo resultante: '.Self::formato($saldo_resultante).'. '.
                'Límite: '.Self::formato($limite).'.',
            'limite_credito'        => [
                'client_id'         => $client_id,
                'client_name'       => $client_name,
                'credit_account_id' => $credit_account_id,
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
