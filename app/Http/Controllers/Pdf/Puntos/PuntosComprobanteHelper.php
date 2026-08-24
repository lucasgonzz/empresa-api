<?php

namespace App\Http\Controllers\Pdf\Puntos;

use App\Http\Controllers\Helpers\Numbers;
use App\Http\Controllers\Helpers\SaleHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Helpers\puntos\PuntosConfigHelper;
use App\Http\Controllers\Helpers\puntos\PuntosSaldoHelper;
use App\Models\MovimientoPunto;

/**
 * Los renglones de puntos que van impresos en un comprobante de venta.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  🔴 POR QUÉ EXISTE ESTA CLASE Y NO SE ESCRIBE EL TEXTO EN CADA PDF.
 *
 *  Los comprobantes de venta vivos son TRES —`NewSalePdf` (A4 comercial y fiscal),
 *  `SaleTicketPdf` (ticket de 80mm) y `SaleAfipTicketPdf` (factura A4 de AFIP)— y los tres
 *  tienen que decir lo mismo. Si el texto y, sobre todo, las CONDICIONES de cuándo se imprime
 *  se escribieran tres veces, el primero que se olvide una condición imprime una cosa distinta
 *  en el papel del mismo cliente. Es la clase "el mismo invariante decidido con dos criterios
 *  distintos" de APRENDER_NO_PARCHEAR.md.
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  🔴 EL DEFECTO QUE ESTO CIERRA: EL COMPROBANTE NO CERRABA.
 *
 *  El canje de puntos baja `sales.total` (`sales.descuento_puntos`, `sales.puntos_canjeados`)
 *  pero ningún comprobante lo nombraba. El papel salía con
 *
 *      Sub Total: $121.000
 *      Total:     $116.000
 *
 *  y $5.000 de diferencia que el cliente no podía explicar. El renglón de
 *  `renglon_descuento()` es lo que hace que Sub Total − descuentos = Total otra vez, y es lo
 *  ÚNICO obligatorio de este archivo: se imprime siempre que haya canje, sin preguntar nada
 *  más y sin pagar ni una consulta.
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  🔴 EL GATE DE LA EXTENSIÓN, Y POR QUÉ SE PREGUNTA CON EL User QUE EL PDF YA TIENE.
 *
 *  Un comercio sin la extensión `puntos_clientes` no puede ver ni un renglón nuevo en sus
 *  comprobantes NI pagar una consulta de más al imprimir. Por eso el orden de las salidas es:
 *
 *    1. `renglon_descuento()` / `tiene_canje()`: miran DOS COLUMNAS de `sales` y nada más.
 *       Cero consultas para todo el mundo. Un comercio sin el módulo nunca tiene
 *       `descuento_puntos > 0`, así que el gate de la extensión ahí sería redundante y caro.
 *
 *    2. `renglones_puntos()`: pregunta primero por la extensión usando el objeto `User` que el
 *       PDF YA cargó (`$sale->user`), NO `PuntosConfigHelper::tiene_extencion($user_id)`, que
 *       hace un `User::find()` extra. La relación `extencions` ya viene caliente porque
 *       cualquier comprobante corre otros `hasExtencion()` antes (`article_variants`,
 *       `firma_entrega_en_pdf_ventas`, ...). Resultado: el comercio sin el módulo paga CERO
 *       consultas por este bloque. El criterio de la respuesta es el mismo que el del helper
 *       canónico —la extensión es del DUEÑO, no del empleado—, y el caso raro de una venta a
 *       nombre de un empleado se delega en `PuntosConfigHelper`, que resuelve el `owner_id` y
 *       memoiza.
 * ─────────────────────────────────────────────────────────────────────────────
 */
class PuntosComprobanteHelper {

    /**
     * Tolerancia de un centavo, el mismo criterio que `PuntosSaldoHelper::DELTA`.
     */
    const DELTA = 0.01;

    /**
     * ¿Esta venta pagó parte con puntos?
     *
     * Cero consultas: son dos columnas de `sales`. La usan los comprobantes para decidir si
     * corresponde imprimir el Sub Total (un canje solo, sin descuentos ni recargos, también
     * abre la diferencia entre el bruto y el total).
     *
     * @param  \App\Models\Sale  $sale
     * @return bool
     */
    static function tiene_canje($sale) {
        return self::descuento_del_canje($sale) > self::DELTA;
    }

    /**
     * Los pesos que el canje descontó de esta venta.
     *
     * @param  \App\Models\Sale  $sale
     * @return float
     */
    static function descuento_del_canje($sale) {

        if (is_null($sale)) {
            return 0;
        }

        return self::a_float($sale->descuento_puntos);
    }

    /**
     * El renglón que explica el canje adentro del bloque de totales, o null si no hubo canje.
     *
     * 🔴 Va en el MISMO bloque que los descuentos y recargos, no en un aparte: lo que tiene que
     * cerrar es Sub Total − descuentos = Total, y un renglón informativo abajo del Total no
     * cierra nada.
     *
     * El importe se imprime en la moneda de la venta porque el canje viaja en la moneda de la
     * venta: el front manda `total` ya neteado con este mismo número
     * (ver `AfipItemCalculator::get_porcentaje_descuento_puntos()`).
     *
     * @param  \App\Models\Sale  $sale
     * @return string|null
     */
    static function renglon_descuento($sale) {

        $descuento = self::descuento_del_canje($sale);

        if ($descuento <= self::DELTA) {
            return null;
        }

        $texto = 'Menos '.Numbers::price($descuento, true, $sale->moneda_id).' (canje de '
                    .self::formato_puntos($sale->puntos_canjeados).' puntos)';

        return $texto;
    }

    /**
     * El mismo renglón, corto, para el ticket de 80mm: ahí no entra el texto largo.
     *
     * @param  \App\Models\Sale  $sale
     * @return string|null
     */
    static function renglon_descuento_corto($sale) {

        $descuento = self::descuento_del_canje($sale);

        if ($descuento <= self::DELTA) {
            return null;
        }

        return 'Canje '.self::formato_puntos($sale->puntos_canjeados).' pts';
    }

    /**
     * Los renglones informativos de puntos: cuántos sumó esta compra y cuánto tiene acumulado.
     *
     * ─────────────────────────────────────────────────────────────────────────────
     *  🔴 POR QUÉ EN UNA VENTA DE CUENTA CORRIENTE NO SE IMPRIME "SUMASTE X PUNTOS".
     *
     *  Los puntos suman AL COBRAR, no al facturar (decisión de Lucas, y es lo que hace
     *  `PuntosAcumulacionHelper::corresponde_acumular()`: una venta de cuenta corriente no
     *  otorga nada hasta que TODOS sus débitos están en 'pagado'). Cuando se imprime la
     *  factura de una venta a cuenta corriente, el movimiento 'ganados' TODAVÍA NO EXISTE.
     *
     *  Imprimir ahí "sumaste 100 puntos" sería mentir dos veces: los puntos no están, y si el
     *  cliente nunca paga no van a estar nunca. Y calcular cuántos VAN a ser tampoco sirve:
     *  el monto depende de qué renglones caen en una lista de precio habilitada y de si la
     *  venta termina saldada, cosas que recién se saben al cobrar.
     *
     *  Por eso hay dos redacciones distintas:
     *
     *    - Venta ya acreditada (mostrador cobrada, o cuenta corriente ya saldada): existe el
     *      movimiento 'ganados' y se imprime el número REAL leído del libro, nunca uno
     *      recalculado. Si la venta se anuló, el movimiento queda con `anulado_at` y este
     *      renglón desaparece solo.
     *
     *    - Venta de cuenta corriente sin acreditar: NO se promete un número ni se promete que
     *      esta compra vaya a sumar. Se imprime la REGLA del programa —los puntos se acreditan
     *      cuando la compra queda paga—, que es verdadera para cualquier venta, sume o no.
     *
     *  El saldo acumulado, en cambio, se puede leer siempre y es el mismo dato en los dos
     *  casos: `PuntosSaldoHelper::saldo()`, la única definición de saldo que existe.
     * ─────────────────────────────────────────────────────────────────────────────
     *
     * @param  \App\Models\Sale       $sale
     * @param  \App\Models\User|null  $user  El comercio, si el comprobante ya lo tiene cargado
     *                                       (`$this->user`). Se usa SOLO si su id coincide con
     *                                       `sales.user_id`; si no, se ignora y se resuelve por
     *                                       la relación. Ver comercio_con_extencion().
     * @return array<int, string>
     */
    static function renglones_puntos($sale, $user = null) {

        if (is_null($sale)) {
            return [];
        }

        /*
         * Salida barata número uno: la extensión, preguntada con el User que el PDF ya tiene.
         */
        if (!self::comercio_con_extencion($sale, $user)) {
            return [];
        }

        /*
         * Salida barata número dos: los puntos son POR CLIENTE. Una venta de mostrador sin
         * cliente no tiene saldo que mostrar ni a quién mostrárselo.
         */
        if (is_null($sale->client_id) || !$sale->client_id) {
            return [];
        }

        $sistema = PuntosConfigHelper::programa_activo($sale->user_id);

        /*
         * Extensión prendida pero sin programa activo: el comercio todavía no configuró nada.
         * No se imprime nada, ni siquiera el saldo, porque sin programa no hay nada que el
         * cliente pueda hacer con esos puntos.
         */
        if (is_null($sistema)) {
            return [];
        }

        $renglones = [];

        $ganados = self::puntos_ganados($sale);

        if ($ganados > self::DELTA) {

            $renglones[] = 'Sumaste '.self::formato_puntos($ganados).' puntos con esta compra';

        } else if (SaleHelper::va_a_volver_a_la_cuenta_corriente($sale)) {

            $renglones[] = 'Los puntos de esta compra se acreditan cuando quede paga';
        }

        $saldo = PuntosSaldoHelper::saldo($sale->user_id, $sale->client_id);

        $texto_saldo = 'Puntos acumulados: '.self::formato_puntos($saldo);

        $valor_punto = self::a_float($sistema->valor_punto);

        if ($saldo > self::DELTA && $valor_punto > 0) {

            /*
             * La equivalencia va SIEMPRE en pesos (moneda_id 1) aunque la venta sea en dólares:
             * `sistemas_de_puntos.valor_punto` está definido en pesos y traducirlo a la moneda
             * de la venta requeriría una cotización que el programa de puntos no tiene.
             */
            $texto_saldo .= ' (equivalen a '.Numbers::price(round($saldo * $valor_punto, 2), true, 1).')';
        }

        $renglones[] = $texto_saldo;

        return $renglones;
    }

    /**
     * Los puntos que esta venta le acreditó al cliente, leídos del libro de movimientos.
     *
     * 🔴 Se SUMAN todos los 'ganados' de la venta y no se toma el primero: una venta mixta
     * —renglones con dos listas de precio distintas— escribe un lote por lista
     * (`movimiento_puntos_sale_tipo_price_type_unique`), y el cliente sumó los dos.
     *
     * Los lotes con `anulado_at` quedan afuera: son los de una venta que se anuló o se editó,
     * ya revertidos por el total, y el cliente no los tiene.
     *
     * @param  \App\Models\Sale  $sale
     * @return float
     */
    private static function puntos_ganados($sale) {

        if (is_null($sale->id)) {
            return 0;
        }

        return (float) MovimientoPunto::where('sale_id', $sale->id)
                                        ->where('tipo', 'ganados')
                                        ->whereNull('anulado_at')
                                        ->sum('puntos');
    }

    /**
     * ¿El comercio de esta venta tiene prendida la extensión `puntos_clientes`?
     *
     * Ver el docblock de la clase: se pregunta con `$sale->user`, que el PDF ya cargó, para no
     * pagar un `User::find()` por comprobante impreso.
     *
     * @param  \App\Models\Sale       $sale
     * @param  \App\Models\User|null  $user_precargado
     * @return bool
     */
    private static function comercio_con_extencion($sale, $user_precargado = null) {

        $user = null;

        /*
         * 🔴 El User precargado se acepta SOLO si es el mismo comercio que la venta. Varios
         * comprobantes arman `$this->user` con `UserHelper::getFullModel()`, que devuelve el
         * comercio de la SESIÓN, no el de la venta. En el 99% de los casos son el mismo, pero
         * si algún día dejan de serlo, la extensión tiene que salir de `sales.user_id` —que es
         * con lo que trabaja todo el resto del módulo— y no de quién apretó imprimir. La
         * comparación es la que hace que "de quién es la extensión" siga teniendo una sola
         * respuesta posible.
         */
        if (!is_null($user_precargado) && (int) $user_precargado->id === (int) $sale->user_id) {
            $user = $user_precargado;
        }

        if (is_null($user)) {
            $user = $sale->user;
        }

        if (is_null($user)) {
            return false;
        }

        /*
         * `sales.user_id` es el DUEÑO (UserHelper::userId() resuelve el owner antes de
         * guardar), así que el camino normal no necesita resolver nada. Si igual llegara una
         * venta a nombre de un empleado, se delega en el helper canónico, que busca el dueño y
         * memoiza la respuesta.
         */
        if ($user->owner_id) {
            return PuntosConfigHelper::tiene_extencion($user->id);
        }

        return UserHelper::hasExtencion(PuntosConfigHelper::SLUG_EXTENCION, $user);
    }

    /**
     * Los puntos, formateados para el papel.
     *
     * Sin decimales cuando son redondos (que es el caso normal: los puntos se otorgan por
     * tramo) y con dos cuando no, para no imprimir "500,00 puntos" en un ticket de 80mm.
     *
     * @param  mixed  $puntos
     * @return string
     */
    private static function formato_puntos($puntos) {

        $valor = self::a_float($puntos);

        $decimales = abs($valor - round($valor)) > self::DELTA ? 2 : 0;

        return number_format($valor, $decimales, ',', '.');
    }

    /**
     * null / '' / string numérico -> float. Mismo criterio que `PuntosBaseHelper::a_float()`.
     *
     * @param  mixed  $valor
     * @return float
     */
    private static function a_float($valor) {

        if (is_null($valor) || $valor === '') {
            return 0;
        }

        return (float) $valor;
    }
}
