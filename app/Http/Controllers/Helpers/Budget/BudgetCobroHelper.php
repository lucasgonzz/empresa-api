<?php

namespace App\Http\Controllers\Helpers\Budget;

use App\Exceptions\CobroDePresupuestoInvalidoException;
use App\Http\Controllers\Helpers\ChequeHelper;
use App\Http\Controllers\Helpers\PaymentMethodHelper;
use App\Http\Controllers\Helpers\SaleHelper;
use App\Http\Controllers\Helpers\UserHelper;
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
 *  LAS FILAS SE NORMALIZAN ANTES DE VALIDAR Y ANTES DE GUARDAR
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  `normalizar_filas()` descarta las filas que no cobran nada (monto null, '' o 0). El modal de
 *  Vender agrega por defecto una fila (método 3, monto '') y un reparto con un método en 0 manda
 *  otra: con caja, cada una creaba un movimiento de $ 0; sin caja, una fila basura en el pivote.
 *  Lo que se guarda en la columna, lo que se valida y lo que se le adjunta a la venta son siempre
 *  las filas YA normalizadas.
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
 *  V1  hay método de pago válido (y ninguna fila apunta a un método que ya no existe);
 *  V1b los montos son números y no son negativos;
 *  V2  cada caja elegida es un id bien formado, existe, es del dueño del presupuesto y está abierta;
 *  V3  el reparto suma el total del presupuesto;
 *  V4  ninguna fila endosa un cheque recibido (una venta no endosa: la fila reventaría al confirmar).
 *
 *  Una sola excepción a "corren dos veces": cuando el PUT del form genérico reenvía el mismo cobro
 *  que ya estaba guardado, solo se repite V3 (ver `es_el_cobro_guardado()`).
 */
class BudgetCobroHelper {

    /**
     * Tolerancia de la suma del reparto contra el total, en pesos. Son redondeos de centavos de la
     * SPA (el modal trunca/redondea por fila), no una diferencia de negocio.
     */
    const TOLERANCIA_DEL_REPARTO = 0.05;

    /** El 422 de un monto que no es un número (`'abc'`, un array, un booleano). */
    const MENSAJE_MONTO_NO_NUMERICO = 'Hay un método de pago con un monto que no es un número.';

    /** El 422 de un monto negativo: llegaba a la caja como un ingreso negativo. */
    const MENSAJE_MONTO_NEGATIVO = 'Los montos de los métodos de pago no pueden ser negativos.';

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
     * El reparto como lista de filas SIN TOCAR NINGUNA: solo las que son arrays, reindexadas.
     * Acepta el array del cast del modelo, el del request o un JSON crudo.
     *
     * Es la mirada de "hay un reparto o no" (la condición 2 de la regla de contado): una fila en 0
     * cuenta como reparto pedido, así el request que trae SOLO filas en 0 llega a la validación y
     * recibe el 422 `sin_metodo_de_pago` en vez de caer a cuenta corriente en silencio. Para
     * validar, guardar y cobrar se usa `normalizar_filas()`.
     *
     * @param  mixed  $valor
     * @return array
     */
    static function filas_crudas($valor) {

        if (is_string($valor)) {
            $valor = json_decode($valor, true);
        }

        if (!is_array($valor)) {
            return [];
        }

        $filas = [];

        foreach ($valor as $fila) {

            if (is_array($fila)) {
                $filas[] = $fila;
            }
        }

        return $filas;
    }

    /**
     * ¿El monto de una fila está "en blanco"? null, '' (o solo espacios) o un número que vale cero.
     *
     * Un monto que NO es un número (`'abc'`) NO está en blanco: es un monto inválido y tiene que
     * llegar a la validación para dar el 422 y no desaparecer en silencio.
     *
     * @param  mixed  $monto
     * @return bool
     */
    static function monto_en_blanco($monto) {

        if (is_null($monto)) {
            return true;
        }

        if (is_string($monto) && trim($monto) === '') {
            return true;
        }

        return is_numeric($monto) && (float) $monto == 0.0;
    }

    /**
     * Las filas NORMALIZADAS: las crudas menos las que no cobran nada. Lo que se valida, lo que se
     * guarda en `budgets.selected_payment_methods` y lo que se le adjunta a la venta.
     *
     * 🔴 UNA FILA CUYO `amount` ES null / '' / 0 SE DESCARTA. El modal de Vender agrega por defecto
     * una fila (método 3, monto ''), y un vendedor que reparte y deja un método en 0 manda otra:
     * con caja, cada una creaba un movimiento de $ 0 en la caja; sin caja, una fila basura en el
     * pivote. Es la misma limpieza que hace `Buttons.vue::terminar()` con las filas sin método, pero
     * del lado que no se puede saltear.
     *
     * Solo se descartan las filas SIN monto. Las que tienen un monto inválido (`'abc'`, negativo) se
     * quedan: las rechaza `motivo_de_cobro_invalido()` con su 422, que es lo que tiene que pasar.
     * El resto de cada fila se guarda "tal cual lo arma la SPA", incluidas las claves que el back
     * no lee (la SPA las usa para reconstruir el modal al reabrir el presupuesto).
     *
     * @param  mixed  $valor  El reparto crudo (request, columna o JSON).
     * @return array
     */
    static function normalizar_filas($valor) {

        $normalizadas = [];

        foreach (Self::filas_crudas($valor) as $fila) {

            $monto = array_key_exists('amount', $fila) ? $fila['amount'] : null;

            if (Self::monto_en_blanco($monto)) {
                continue;
            }

            $normalizadas[] = $fila;
        }

        return $normalizadas;
    }

    /**
     * Las filas del reparto GUARDADAS en el presupuesto, normalizadas: las que cobran, las que
     * alimentan el ajuste, la validación al confirmar y la venta. Vacío si no hay reparto o si el
     * modelo no tiene el atributo (columna sin migrar): null es "sin reparto".
     *
     * Normaliza otra vez al leer porque la columna puede traer filas viejas, guardadas antes de que
     * existiera la limpieza.
     *
     * @param  \App\Models\Budget  $budget
     * @return array
     */
    static function filas($budget) {

        if (!is_object($budget) || !isset($budget->selected_payment_methods)) {
            return [];
        }

        return Self::normalizar_filas($budget->selected_payment_methods);
    }

    /**
     * Las filas del reparto que viajan en el request, normalizadas, o null si el request no es de
     * contado. Es lo que `BudgetController` guarda en `budgets.selected_payment_methods`.
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
     * "Al menos una fila" se mira sobre las filas CRUDAS: un presupuesto guardado con filas pero
     * todas en 0 (datos viejos) sigue siendo de contado y al confirmar recibe el 422
     * `sin_metodo_de_pago`, en vez de pasar a cuenta corriente sin que nadie lo decida.
     *
     * @param  \App\Models\Budget  $budget
     * @return bool
     */
    static function es_de_contado($budget) {

        /*
            🔴 `isset()` y no una lectura pelada, y ANTES de preguntar por el esquema: este metodo lo
            alcanza `BudgetHelper::getTotal()`, y `getTotal()` lo llaman tambien `OrderProductionPdf` y
            `Pdf/__base.php` con modelos que NO son presupuestos (un `OrderProduction`) y que nunca van
            a tener ninguna de las dos columnas. Misma razon, y mismo estilo, que
            `SaleHelper::get_forzar_total_monto()`. Un modelo sin el reparto es "no es de contado",
            sin una sola consulta.
        */
        if (!is_object($budget) || !isset($budget->omitir_en_cuenta_corriente) || !isset($budget->selected_payment_methods)) {
            return false;
        }

        if (!CobroPresupuestoEsquemaHelper::hay_columna()) {
            return false;
        }

        if (!Self::es_verdadero($budget->omitir_en_cuenta_corriente)) {
            return false;
        }

        return count(Self::filas_crudas($budget->selected_payment_methods)) >= 1;
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

        return count(Self::filas_crudas($request->selected_payment_methods)) >= 1;
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

    /**
     * ¿El cobro que viaja en el request es EL MISMO que el presupuesto ya tiene guardado?
     *
     * 🔴 EXISTE POR EL FORM GENERICO DEL MODULO PRESUPUESTOS. `getModelToSend()` de `common-vue`
     * (`components/model/Index.vue`) manda en cada PUT `{...this.model}`: el modelo ENTERO, tal como
     * lo devolvio el listado, o sea con `omitir_en_cuenta_corriente` y `selected_payment_methods`
     * incluidos. Para `update()` eso es "clave presente" en cada guardado, y sin esta comparacion
     * revalidaria V2 contra el cobro de siempre: un presupuesto de contado guardado a la manana con
     * la Caja 1 no dejaria editar ni las observaciones a la noche, con la caja cerrada (422
     * `caja_cerrada`). Quien no toca el cobro no tiene por que volver a justificarlo.
     *
     * Se comparan los dos cobros YA normalizados y por su firma (`firma_del_cobro()`): se ignoran
     * el `__row_id` de la SPA, el orden de las claves y las diferencias de tipo (200 / "200" /
     * "200.00"). El orden de las FILAS si cuenta.
     *
     * Solo es verdadero para dos cobros de contado. Dos presupuestos a cuenta corriente no tienen
     * nada que comparar (no se valida nada) y la columna sin migrar nunca es de contado.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Budget        $budget   El presupuesto TAL COMO ESTA GUARDADO, antes del update.
     * @return bool
     */
    static function es_el_cobro_guardado($request, $budget) {

        if (!Self::es_de_contado_request($request) || !Self::es_de_contado($budget)) {
            return false;
        }

        return Self::firma_del_cobro(Self::normalizar_filas($request->selected_payment_methods))
            === Self::firma_del_cobro(Self::filas($budget));
    }

    /**
     * Una cadena canonica del reparto para compararlo con otro: sin `__row_id`, con las claves de
     * cada fila ordenadas y los escalares en una forma unica (numeros como float a 4 decimales,
     * '' como null, booleanos como 0/1).
     *
     * @param  array  $filas  Filas ya normalizadas.
     * @return string
     */
    protected static function firma_del_cobro($filas) {

        $canonicas = [];

        foreach ($filas as $fila) {

            unset($fila['__row_id']);

            $canonicas[] = Self::canonizar($fila);
        }

        return json_encode($canonicas);
    }

    /**
     * @param  mixed  $valor
     * @return mixed
     */
    protected static function canonizar($valor) {

        if (is_array($valor)) {

            $canonico = [];

            foreach ($valor as $clave => $hijo) {
                $canonico[$clave] = Self::canonizar($hijo);
            }

            ksort($canonico);

            return $canonico;
        }

        if (is_bool($valor)) {
            return $valor ? 1.0 : 0.0;
        }

        if (is_numeric($valor)) {
            return round((float) $valor, 4);
        }

        if (is_string($valor) && trim($valor) === '') {
            return null;
        }

        return $valor;
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
     * @param  bool  $conserva_el_cobro_guardado  `true` cuando `es_el_cobro_guardado()` dio verdadero:
     *         el cobro no cambio, asi que NO se vuelven a pedir V1, los montos, V4 ni V2 (la caja se
     *         pudo cerrar desde que se guardo y eso no impide editar una observacion). V3 SI: el
     *         `total` del request pudo cambiar y el reparto guardado ya no sumarlo.
     * @return array|null  El cuerpo del 422, o null.
     */
    static function validar_request($request, $conserva_el_cobro_guardado = false) {

        if (!Self::pide_cobro_el_request($request)) {
            return null;
        }

        if (!CobroPresupuestoEsquemaHelper::hay_columna()) {

            // No es un error: es la ventana del deploy. Se guarda a cuenta corriente y la SPA avisa.
            CobroPresupuestoEsquemaHelper::avisar_columna_faltante('BudgetCobroHelper::validar_request');

            return null;
        }

        $filas = Self::normalizar_filas($request->selected_payment_methods);

        if ($conserva_el_cobro_guardado) {
            return Self::motivo_de_suma_invalida($filas, $request->total);
        }

        /*
            El dueño de las cajas es el de la cuenta, no el empleado que carga: `Caja.user_id` es el id
            del dueño (`CajaController` las lista con `where('user_id', $this->userId())`, que resuelve
            el owner).
        */
        return Self::motivo_de_cobro_invalido($filas, $request->total, UserHelper::userId());
    }

    /**
     * Re-valida el cobro GUARDADO antes de crear la venta. Si el presupuesto no es de contado no
     * hace nada. Si el cobro ya no sirve, lanza `CobroDePresupuestoInvalidoException` con el cuerpo
     * del 422; el controlador hace rollback y contesta.
     *
     * Va ANTES de crear nada en `saveSale()`: la venta, el stock y los movimientos no existen
     * todavía cuando se corta. Aplica las MISMAS reglas que el guardado sobre lo que hay en la
     * columna, filas viejas incluidas (montos en 0, negativos, cajas de otro dueño).
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

        $cuerpo = Self::motivo_de_cobro_invalido(Self::filas($budget), $budget->total, $budget->user_id);

        if (!is_null($cuerpo)) {

            Log::info('confirmar presupuesto '.$budget->id.': el cobro guardado ya no es valido ('.$cuerpo['message'].').');

            throw new CobroDePresupuestoInvalidoException($cuerpo);
        }
    }

    /**
     * V1 a V4 sobre un reparto y un total, compartidas por el guardado y la confirmación.
     *
     * @param  array  $filas     El reparto (se normaliza de nuevo: es idempotente y asegura que las dos
     *                           puntas validen lo mismo que se guarda).
     * @param  mixed  $total     El total NETO del presupuesto (con el ajuste adentro).
     * @param  int    $user_id   El dueño de las cajas: solo se aceptan cajas suyas.
     * @return array|null  El cuerpo del 422, o null si el cobro sirve.
     */
    static function motivo_de_cobro_invalido($filas, $total, $user_id) {

        $filas = Self::normalizar_filas($filas);

        // V1: hay un método de pago real. Mismo criterio y mismo texto que la venta de Vender.
        if (!PaymentMethodHelper::reparto_tiene_un_metodo_valido($filas)) {

            return Self::cuerpo_sin_metodo(PaymentMethodHelper::mensaje_sin_metodo_de_pago());
        }

        /*
            V1b: los montos son numeros no negativos. Sin esto un `'abc'` pasaba V3 (se sumaba como 0)
            y reventaba recien al confirmar con un 500 de SQL crudo ("1366 Incorrect decimal value"),
            y un monto negativo --un reparto de 250 + (-50) suma el total de 200-- llegaba a la caja
            como un ingreso negativo. `amount_cotizado` se mira si viene (la fila por defecto del modal
            lo manda vacio).
        */
        foreach ($filas as $fila) {

            foreach (['amount', 'amount_cotizado'] as $clave) {

                $monto = array_key_exists($clave, $fila) ? $fila[$clave] : null;

                if ($clave === 'amount_cotizado' && (is_null($monto) || (is_string($monto) && trim($monto) === ''))) {
                    continue;
                }

                if (!is_numeric($monto)) {

                    return ['message' => Self::MENSAJE_MONTO_NO_NUMERICO, 'cobro_invalido' => true];
                }

                if ((float) $monto < 0) {

                    return ['message' => Self::MENSAJE_MONTO_NEGATIVO, 'cobro_invalido' => true];
                }
            }
        }

        /*
            V1, segunda mitad: ninguna fila apunta a un método que no existe. La reutilizada
            `reparto_tiene_un_metodo_valido()` pide solo UN renglón válido, y `attach_payment_methods()`
            saltea los inválidos en silencio: con dos filas y una apuntando a un método borrado, la
            venta nacería cobrada por la mitad y V3 (que suma las filas sin mirar el método) no se
            daría cuenta. Para el presupuesto, que se confirma días después, se exige más que a
            Vender. Después de normalizar, TODA fila tiene monto.
        */
        foreach ($filas as $fila) {

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

        // V2: cajas bien formadas, del dueño, existentes y abiertas.
        $cajas_vistas = [];

        foreach ($filas as $fila) {

            $caja_id = Self::caja_id_de_la_fila($fila);

            if ($caja_id === false) {

                return Self::cuerpo_caja_inexistente();
            }

            if ($caja_id === 0 || isset($cajas_vistas[$caja_id])) {
                continue;
            }

            $cajas_vistas[$caja_id] = true;

            /*
                🔴 FILTRADA POR DUEÑO. `Caja::find()` a secas aceptaba la caja de OTRO comercio (en las
                bases compartidas viejas conviven 51) y el movimiento se escribia ahi: plata de un
                cliente en el arqueo de otro. Una caja ajena se contesta igual que una inexistente, sin
                confirmar que existe del otro lado.
            */
            $caja = Caja::where('id', $caja_id)->where('user_id', $user_id)->first();

            if (is_null($caja)) {

                return Self::cuerpo_caja_inexistente();
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
        return Self::motivo_de_suma_invalida($filas, $total);
    }

    /**
     * V3: el reparto suma el total del presupuesto (con una tolerancia de centavos).
     *
     * @param  array  $filas  Filas ya normalizadas.
     * @param  mixed  $total  El total NETO.
     * @return array|null
     */
    static function motivo_de_suma_invalida($filas, $total) {

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
     * El `caja_id` de una fila, leído con UNA regla que no discrepa de la de
     * `PaymentMethodHelper::attach_payment_methods()`.
     *
     * 🔴 Esa funcion adjunta la caja con `isset($fila['caja_id']) && $fila['caja_id'] != 0`, que es
     * una comparacion laxa: `true` pasa (y se guarda como la caja 1) y `"190.5"` tambien (y el INSERT
     * revienta al confirmar). Si la validacion de V2 leyera el id de otra forma, validaria una caja
     * distinta de la que despues se cobra. Por eso acá se acepta SOLO lo que tiene una unica lectura:
     *
     *   - sin caja: ausente, null, '' o 0 (el 0 de la SPA para "sin caja"), devuelve 0;
     *   - una caja: un entero positivo o un string de digitos puros, devuelve el entero;
     *   - cualquier otra cosa (booleano, decimal, texto, negativo, array): devuelve false.
     *
     * @param  array  $fila
     * @return int|false
     */
    protected static function caja_id_de_la_fila($fila) {

        if (!array_key_exists('caja_id', $fila)) {
            return 0;
        }

        $caja_id = $fila['caja_id'];

        if (is_null($caja_id) || $caja_id === '' || $caja_id === 0 || $caja_id === '0') {
            return 0;
        }

        if (is_int($caja_id)) {
            return $caja_id > 0 ? $caja_id : false;
        }

        if (is_string($caja_id) && ctype_digit($caja_id)) {
            return (int) $caja_id;
        }

        return false;
    }

    /**
     * El cuerpo del 422 de una caja que no sirve porque no existe, no es del dueño o viene mal formada.
     *
     * @return array
     */
    protected static function cuerpo_caja_inexistente() {

        return [
            'message'           => 'La caja elegida para cobrar este presupuesto ya no existe. Elegí otra.',
            'caja_inexistente'  => true,
            'cobro_invalido'    => true,
        ];
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
