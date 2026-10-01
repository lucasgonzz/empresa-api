<?php

namespace App\Http\Controllers\Helpers\Budget;

use App\Exceptions\CobroDePresupuestoInvalidoException;
use App\Http\Controllers\Helpers\ChequeHelper;
use App\Http\Controllers\Helpers\PaymentMethodHelper;
use App\Http\Controllers\Helpers\SaleHelper;
use App\Models\Caja;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * El cobro de un presupuesto "de contado" (misión presupuesto-contado-o-cuenta-corriente,
 * 1/10/2026).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  LA DECISIÓN QUE ESTA CLASE LEVANTA (PARCIALMENTE)
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  El 18/9/2026 Lucas decidió que "un presupuesto va SIEMPRE a la cuenta corriente" (misión
 *  vender-lista-obligatoria, tanda 3). El motivo era concreto: el presupuesto no guardaba ningún
 *  dato de cobro, y la venta que nacía al confirmarlo desde el listado quedaba "de contado" sin
 *  método de pago ni caja, que es justo lo que `SaleController::store()` rechaza con el 422
 *  `sin_metodo_de_pago`. El 1/10/2026 Lucas pidió poder elegir al guardar: "no cuenta corriente" +
 *  el reparto de métodos de pago igual que en Vender. Como ahora el presupuesto GUARDA el reparto
 *  (`budgets.selected_payment_methods`), esa causa desaparece.
 *
 *  🔴 EL DEFAULT SIGUE SIENDO CUENTA CORRIENTE. Todo presupuesto viejo, todo presupuesto que no
 *  pase por el cartel de la SPA y todo payload de una SPA anterior se comporta exactamente como
 *  antes. Por eso la regla de abajo exige DOS cosas.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  LA REGLA DE "DE CONTADO" (única, y está acá)
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  Un presupuesto es de contado si y solo si:
 *
 *    1. `omitir_en_cuenta_corriente` es verdadero, Y
 *    2. `selected_payment_methods` tiene al menos una fila, Y
 *    3. la columna existe en esa base (`CobroPresupuestoEsquemaHelper`).
 *
 *  Cualquier otra combinación es cuenta corriente. Lo que mantiene compatible a una SPA vieja que
 *  manda `omitir_en_cuenta_corriente: 1` sin reparto (el store de Vender lo arrastraba hasta el
 *  18/9): sin la condición 2 esa SPA crearía una venta de contado sin cobro, la que causó la
 *  decisión del 18/9. No repetir esta condición en otro lado: llamar a `es_de_contado()` (sobre un
 *  presupuesto) o `es_de_contado_request()` (sobre un request).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  EL AJUSTE POR MÉTODO DE PAGO NO TIENE COLUMNA
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  Descuento por transferencia, recargo por cuotas: la SPA los calcula por fila y las filas finales
 *  conservan `discount_amount` y `surchage_amount`, con los `amount` ya NETOS. El ajuste del
 *  presupuesto es `Σ surchage_amount − Σ discount_amount`, derivado de las filas guardadas
 *  (`ajuste_por_metodos_de_pago()`). Una sola fuente de verdad: no puede desfasarse de las filas,
 *  y `BudgetHelper::getTotal()` lo suma justo antes del forzado, en el mismo orden que la pantalla
 *  de Vender (modal de métodos → forzado).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  LAS VALIDACIONES (422, siempre con `cobro_invalido: true`)
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  Corren dos veces, con el mismo código (`motivo_de_cobro_invalido()`):
 *   - al GUARDAR (`validar_request()`, antes de abrir la transacción), sobre el request;
 *   - al CONFIRMAR (`validar_para_confirmar()`, adentro de `BudgetHelper::saveSale()`), sobre lo
 *     guardado. Es la red para el presupuesto guardado "hace días": la caja se pudo cerrar, el
 *     método se pudo borrar, o el presupuesto se editó por otro camino y el reparto quedó viejo.
 *
 *  V1  hay método de pago válido (y ninguna fila con plata apunta a un método que ya no existe);
 *  V2  cada caja elegida existe y está abierta;
 *  V3  el reparto suma el total del presupuesto;
 *  V4  ninguna fila endosa un cheque recibido (una venta no endosa: la fila reventaría al confirmar).
 */
class BudgetCobroHelper {

    /**
     * Tolerancia de la suma del reparto contra el total, en pesos. Son redondeos de centavos de la
     * SPA (el modal trunca/redondea por fila), no una diferencia de negocio.
     */
    const TOLERANCIA_DEL_REPARTO = 0.05;

    // ─────────────────────────────────────────────────────────────────────────
    //  Lectura de lo guardado y de lo que viaja
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Un valor de "verdadero/falso" tal como llega de la SPA (0/1, true/false, "0"/"1") o de la
     * base (tinyint, a veces string). `(bool) "0"` es false pero `(bool) "false"` es true; este
     * helper lee los dos bien.
     *
     * @param  mixed  $valor
     * @return bool
     */
    static function es_verdadero($valor) {

        if (is_null($valor)) {
            return false;
        }

        return (bool) filter_var($valor, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * El reparto como lista de filas: solo las que son arrays, reindexadas. Acepta el array del
     * cast del modelo, el del request o un JSON crudo.
     *
     * No toca el contenido de las filas: se guarda "tal cual lo arma la SPA", incluidas las claves
     * que el back no lee (la SPA las usa para reconstruir el modal al reabrir el presupuesto).
     *
     * @param  mixed  $filas
     * @return array
     */
    static function normalizar_filas($filas) {

        if (is_string($filas)) {
            $filas = json_decode($filas, true);
        }

        if (!is_array($filas)) {
            return [];
        }

        $normalizadas = [];

        foreach ($filas as $fila) {

            if (is_array($fila)) {
                $normalizadas[] = $fila;
            }
        }

        return $normalizadas;
    }

    /**
     * Las filas del reparto GUARDADAS en el presupuesto. Vacío si no hay reparto o si el modelo no
     * tiene el atributo (columna sin migrar): null es "sin reparto".
     *
     * @param  \App\Models\Budget  $budget
     * @return array
     */
    static function filas($budget) {

        return Self::normalizar_filas($budget->selected_payment_methods);
    }

    /**
     * Las filas del reparto que viajan en el request, o null si el request no es de contado. Es lo
     * que `BudgetController` guarda en `budgets.selected_payment_methods`.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array|null
     */
    static function filas_del_request($request) {

        if (!Self::es_de_contado_request($request)) {
            return null;
        }

        return Self::normalizar_filas($request->selected_payment_methods);
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  La regla de "de contado"
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * ¿El presupuesto GUARDADO es de contado? Ver la regla en el encabezado.
     *
     * @param  \App\Models\Budget  $budget
     * @return bool
     */
    static function es_de_contado($budget) {

        if (!CobroPresupuestoEsquemaHelper::hay_columna()) {
            return false;
        }

        if (!Self::es_verdadero($budget->omitir_en_cuenta_corriente)) {
            return false;
        }

        return count(Self::filas($budget)) >= 1;
    }

    /**
     * ¿El request PIDE un cobro de contado? Las condiciones 1 y 2 de la regla, sin mirar el
     * esquema. Aparte de `es_de_contado_request()` para poder avisar en el log cuando se pide y la
     * columna no está.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return bool
     */
    static function pide_cobro_el_request($request) {

        if (!Self::es_verdadero($request->omitir_en_cuenta_corriente)) {
            return false;
        }

        return count(Self::normalizar_filas($request->selected_payment_methods)) >= 1;
    }

    /**
     * ¿El request es de contado? La misma regla de `es_de_contado()` aplicada a lo que viaja.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return bool
     */
    static function es_de_contado_request($request) {

        return Self::pide_cobro_el_request($request) && CobroPresupuestoEsquemaHelper::hay_columna();
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  El ajuste por método de pago
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * El ajuste del total por los métodos de pago elegidos: `Σ surchage_amount − Σ discount_amount`
     * de las filas guardadas (nulos y no numéricos valen 0). Negativo = descuento, positivo =
     * recargo. 0 si el presupuesto no es de contado.
     *
     * 🔴 SE LEE DE LAS FILAS, NO DE UNA COLUMNA, y solo si es de contado: un presupuesto en cuenta
     * corriente con filas colgadas (no debería haberlas, pero un `selected_payment_methods` viejo no
     * puede inflarle el total) no tiene ajuste.
     *
     * @param  \App\Models\Budget  $budget
     * @return float
     */
    static function ajuste_por_metodos_de_pago($budget) {

        if (!Self::es_de_contado($budget)) {
            return 0.0;
        }

        $ajuste = 0.0;

        foreach (Self::filas($budget) as $fila) {

            if (isset($fila['surchage_amount']) && is_numeric($fila['surchage_amount'])) {
                $ajuste += (float) $fila['surchage_amount'];
            }

            if (isset($fila['discount_amount']) && is_numeric($fila['discount_amount'])) {
                $ajuste -= (float) $fila['discount_amount'];
            }
        }

        return round($ajuste, 2);
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Validaciones V1 a V4
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * El 422 de un request de contado inválido, o null si se puede seguir. Se llama ANTES de abrir
     * la transacción (es una respuesta, no un fallo que haya que revertir). Request que no es de
     * contado: null, sin una sola consulta.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array|null  El cuerpo del 422, o null.
     */
    static function validar_request($request) {

        if (!Self::pide_cobro_el_request($request)) {
            return null;
        }

        if (!CobroPresupuestoEsquemaHelper::hay_columna()) {

            // No es un error: es la ventana del deploy. Se guarda a cuenta corriente y la SPA avisa.
            CobroPresupuestoEsquemaHelper::avisar_columna_faltante('BudgetCobroHelper::validar_request');

            return null;
        }

        return Self::motivo_de_cobro_invalido(
            Self::normalizar_filas($request->selected_payment_methods),
            $request->total
        );
    }

    /**
     * Re-valida el cobro GUARDADO antes de crear la venta. Si el presupuesto no es de contado no
     * hace nada. Si el cobro ya no sirve, lanza `CobroDePresupuestoInvalidoException` con el cuerpo
     * del 422; el controlador hace rollback y contesta.
     *
     * Va ANTES de crear nada en `saveSale()`: la venta, el stock y los movimientos no existen
     * todavía cuando se corta.
     *
     * @param  \App\Models\Budget  $budget
     * @return void
     *
     * @throws \App\Exceptions\CobroDePresupuestoInvalidoException
     */
    static function validar_para_confirmar($budget) {

        if (!Self::es_de_contado($budget)) {
            return;
        }

        $cuerpo = Self::motivo_de_cobro_invalido(Self::filas($budget), $budget->total);

        if (!is_null($cuerpo)) {

            Log::info('confirmar presupuesto '.$budget->id.': el cobro guardado ya no es valido ('.$cuerpo['message'].').');

            throw new CobroDePresupuestoInvalidoException($cuerpo);
        }
    }

    /**
     * V1 a V4 sobre un reparto y un total, compartidas por el guardado y la confirmación.
     *
     * @param  array  $filas  Filas ya normalizadas (arrays).
     * @param  mixed  $total  El total NETO del presupuesto (con el ajuste adentro).
     * @return array|null  El cuerpo del 422, o null si el cobro sirve.
     */
    static function motivo_de_cobro_invalido($filas, $total) {

        // V1: hay un método de pago real. Mismo criterio y mismo texto que la venta de Vender.
        if (!PaymentMethodHelper::reparto_tiene_un_metodo_valido($filas)) {

            return Self::cuerpo_sin_metodo(PaymentMethodHelper::mensaje_sin_metodo_de_pago());
        }

        /*
            V1, segunda mitad: ninguna fila CON PLATA apunta a un método que no existe. La
            reutilizada `reparto_tiene_un_metodo_valido()` pide solo UN renglón válido, y
            `attach_payment_methods()` saltea los inválidos en silencio: con dos filas y una
            apuntando a un método borrado, la venta nacería cobrada por la mitad y V3 (que suma
            las filas sin mirar el método) no se daría cuenta. Para el presupuesto, que se confirma
            días después, se exige más que a Vender.
        */
        foreach ($filas as $fila) {

            $monto = isset($fila['amount']) ? $fila['amount'] : null;

            if (is_null($monto) || $monto === '') {
                continue;
            }

            $metodo = isset($fila['current_acount_payment_method_id']) ? $fila['current_acount_payment_method_id'] : null;

            if (is_null(PaymentMethodHelper::metodo_de_pago_valido($metodo))) {

                return Self::cuerpo_sin_metodo('Uno de los métodos de pago del cobro ya no existe. Volvé a repartir el cobro del presupuesto.');
            }
        }

        // V4: un cheque recibido no se endosa en una venta.
        if (ChequeHelper::payload_pide_endoso($filas)) {

            return [
                'message'           => 'Un presupuesto no se puede cobrar endosando un cheque recibido. Cargá el cheque como uno nuevo.',
                'cobro_invalido'    => true,
            ];
        }

        // V2: cajas existentes y abiertas.
        $cajas_vistas = [];

        foreach ($filas as $fila) {

            $caja_id = isset($fila['caja_id']) && is_numeric($fila['caja_id']) ? (int) $fila['caja_id'] : 0;

            if ($caja_id === 0 || isset($cajas_vistas[$caja_id])) {
                continue;
            }

            $cajas_vistas[$caja_id] = true;

            $caja = Caja::find($caja_id);

            if (is_null($caja)) {

                return [
                    'message'           => 'La caja elegida para cobrar este presupuesto ya no existe. Elegí otra.',
                    'caja_inexistente'  => true,
                    'cobro_invalido'    => true,
                ];
            }

            /*
                `abierta` y no "tiene alguna apertura": `MovimientoCajaHelper` mete el ingreso en la
                ULTIMA apertura de la caja esté la caja abierta o cerrada, y no valida nada. Sin esta
                regla, un presupuesto de contado cobraría en una caja ya cerrada y el movimiento
                caería en un arqueo que ya se hizo.
            */
            if ((int) $caja->abierta !== 1) {

                return [
                    'message'           => 'La caja "'.$caja->name.'" está cerrada. Abrila o elegí otra para cobrar este presupuesto.',
                    'caja_cerrada'      => true,
                    'cobro_invalido'    => true,
                ];
            }
        }

        // V3: el reparto suma el total.
        $suma = 0.0;

        foreach ($filas as $fila) {

            $cotizado = isset($fila['amount_cotizado']) && is_numeric($fila['amount_cotizado']) ? (float) $fila['amount_cotizado'] : 0.0;
            $monto    = isset($fila['amount']) && is_numeric($fila['amount']) ? (float) $fila['amount'] : 0.0;

            $suma += $cotizado > 0 ? $cotizado : $monto;
        }

        $total_numerico = is_numeric($total) ? (float) $total : 0.0;

        // El 0.0001 de más es contra el error binario de la resta: 100.05 − 100.00 da 0.05000000000000426.
        if (abs(round($suma, 2) - round($total_numerico, 2)) > Self::TOLERANCIA_DEL_REPARTO + 0.0001) {

            return [
                'message'           => 'El reparto de métodos de pago no coincide con el total del presupuesto. Volvé a repartir.',
                'cobro_invalido'    => true,
            ];
        }

        return null;
    }

    /**
     * El cuerpo del 422 de "no hay método de pago válido".
     *
     * @param  string  $mensaje
     * @return array
     */
    protected static function cuerpo_sin_metodo($mensaje) {

        return [
            'message'               => $mensaje,
            'sin_metodo_de_pago'    => true,
            'cobro_invalido'        => true,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Aplicar el cobro a la venta que nace al confirmar
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Adjunta a la venta recién creada los métodos de pago del presupuesto, con el mismo helper que
     * usa Vender (`SaleHelper::attachSelectedPaymentMethods()`): los cheques se crean y el pivote
     * queda igual que el de una venta de mostrador.
     *
     * 🔴 SE PASA SOLO EL REPARTO. `attachSelectedPaymentMethods()` tiene dos ramas y la del método
     * único lee `current_acount_payment_method_id`, `discount_amount`, `discount_percentage`,
     * `cuotas` y `caja_id` del request: ninguna de las cuales existe en un presupuesto. Con un
     * reparto no vacío entra siempre por la primera. Por eso el presupuesto exige al menos una fila
     * para ser de contado.
     *
     * ⚠️ La venta tiene que haber nacido con `omitir_en_cuenta_corriente = 1`: el helper solo
     * adjunta si `client_id` es null u omitida. Es lo primero que hace `BudgetHelper::saveSale()`.
     *
     * Deja cargada la relación `current_acount_payment_methods` en la venta, que es lo que lee
     * `SaleCajaHelper::check_caja()` para crear un movimiento por cada fila con caja.
     *
     * @param  \App\Models\Sale    $sale
     * @param  \App\Models\Budget  $budget
     * @return void
     */
    static function adjuntar_cobro_a_la_venta($sale, $budget) {

        $filas = [];

        foreach (Self::filas($budget) as $fila) {

            // `attach_payment_methods()` lee `$fila['amount']` pelado: sin la clave, un notice que
            // Laravel convierte en excepcion y tumba la confirmacion entera.
            if (!array_key_exists('amount', $fila)) {
                $fila['amount'] = null;
            }

            $filas[] = $fila;
        }

        SaleHelper::attachSelectedPaymentMethods($sale, new Request(['selected_payment_methods' => $filas]));

        $sale->load('current_acount_payment_methods');
    }
}
