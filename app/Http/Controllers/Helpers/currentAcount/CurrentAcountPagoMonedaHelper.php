<?php

namespace App\Http\Controllers\Helpers\currentAcount;

use App\Models\Caja;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\CurrentAcountPaymentMethod;
use App\Models\Moneda;

/**
 * Validación y normalización de las MONEDAS de un pago de cuenta corriente (cobro a un cliente o
 * pago a un proveedor), antes de crear nada.
 *
 * EL PROBLEMA. Un pago viaja como un array de filas (`current_acount_payment_methods`) y cada fila
 * dice en qué moneda entregó la plata el cliente (`moneda_id`), con qué cotización se convirtió
 * (`cotizacion`) y a cuánto quedó en la moneda de la cuenta (`amount_cotizado`). Ese último número
 * lo calcula el FRONT (`PaymentMethodsStep.vue::check_moneda()`); el back lo tomaba tal cual y con
 * eso armaba el `haber`. Todo lo que el front puede mandar mal —una PWA vieja, una integración, una
 * cotización borrada a mano— terminaba en plata mal contada sin un solo error: una fila en pesos sin
 * cotizado valía su monto nominal EN DÓLARES (120000 ARS bajaban 120000 USD de deuda), un cotizado
 * residual de otra fila duplicaba el total, una caja en pesos recibía "100" de una fila en dólares.
 * Lo midieron los tests de `tests/Feature/VentasEnDolares/CuentaCorriente_*` (grupo
 * `hallazgo-moneda`).
 *
 * LA REGLA. La cuenta corriente tiene UNA moneda (`credit_accounts.moneda_id`) y el `haber` del pago
 * está siempre en ESA moneda. Cada fila vale:
 *  - en la moneda de la cuenta: su `amount`, y nada más (se ignora cualquier cotización o cotizado
 *    que traiga: es un residuo de otra fila o de otra moneda);
 *  - en otra moneda: su `amount` convertido con la cotización de la fila. La fila en PESOS sobre una
 *    cuenta en otra moneda se divide (`amount / cotizacion`); la fila en cualquier otra moneda sobre
 *    una cuenta en pesos se multiplica (`amount * cotizacion`). Es exactamente la cuenta de
 *    `check_moneda()` del front, para que el back y la pantalla digan lo mismo.
 *
 * 🔴 ES UNA PREVALIDACIÓN, NO ESCRIBE NADA. Se llama desde `CurrentAcountController::pago()` justo
 * después de la prevalidación de cajas, con el mismo criterio: un 422 antes de crear el pago, para
 * que ninguna fila quede a medias. Devuelve las filas normalizadas y el controlador reemplaza las
 * del request por ésas, así todo lo que sigue (el pivote, la caja, el haber) ve datos coherentes.
 *
 * ⚠️ NO PASAN POR ACÁ los llamadores de `CurrentAcountPagoAltaHelper::registrar()` que no son la
 * pantalla (el asistente de IA, el endoso de cheques): arman sus filas ellos mismos. Para ésos,
 * `CurrentAcountPagoAltaHelper::get_haber()` usa `valor_de_la_fila_en_la_cuenta()` de esta clase y
 * cuenta cada fila con la misma regla, sin poder rechazar nada.
 */
class CurrentAcountPagoMonedaHelper {

    /**
     * La cotización pesos/dólares más baja que se acepta en un pago cruzado. Un dólar que vale un
     * peso o menos no existe: es la señal de una cotización sin cargar. Ver el comentario del chequeo.
     */
    const COTIZACION_MINIMA = 1;


    /** Moneda 1 = Peso. Es también lo que el resto del sistema entiende por moneda 0 o null. */
    const MONEDA_PESOS = 1;

    /**
     * Tolerancia mínima, en la moneda de la cuenta, entre el `amount_cotizado` que manda el front y
     * el que sale de la cuenta del back. El front redondea distinto (41,67 contra 41,6667) y una
     * diferencia de centavos no es un dato inconsistente.
     */
    const TOLERANCIA_MINIMA = 0.05;

    /**
     * Tolerancia relativa (0,1 %) del cotizado esperado, para montos grandes: con 12 millones de
     * pesos, 5 centavos de dólar son menos que el redondeo de la cotización.
     */
    const TOLERANCIA_RELATIVA = 0.001;

    /**
     * Valida y normaliza las filas de medios de pago de un pago, más el `to_pay` dirigido.
     *
     * Qué rechaza (devuelve el mensaje, sin tocar nada):
     *  - una fila con `amount` negativo;
     *  - una fila en una moneda distinta a la de la cuenta con cotización vacía, 0 o negativa;
     *  - una fila cruzada cuyo `amount_cotizado` no cierra con `amount` y la cotización (más allá de
     *    la tolerancia);
     *  - una fila con caja cuya moneda no es la de la fila (una fila en dólares apuntando a una caja
     *    en pesos dejaría "100" pesos en la caja);
     *  - un `to_pay` que apunta a un débito de OTRA cuenta corriente (otra moneda).
     *
     * Qué normaliza (en la copia que devuelve, no en la original):
     *  - fila cruzada sin `amount_cotizado` (vacío, 0): se calcula con la cotización. Es lo que manda
     *    una SPA o una PWA vieja que no lo sabía calcular;
     *  - fila en la moneda de la cuenta: `amount_cotizado` y `cotizacion` quedan en null, para que
     *    no se guarden ni cuenten.
     *
     * Una fila sin monto (vacío o 0) se deja EXACTAMENTE como vino: no se valida ni se normaliza. Sin
     * monto no tiene efecto economico (una fila con `amount` vacio se saltea al adjuntar y una con 0 se
     * adjunta pero no mueve saldo ni caja), y el caso real es el usuario que agrega un medio de pago de
     * mas y lo deja vacio.
     *
     * Si la cuenta no existe (un llamador viejo que no manda `credit_account_id`), no se valida
     * nada y el flujo sigue como antes: sin la moneda de la cuenta no hay contra qué comparar.
     *
     * @param  int|string|null  $credit_account_id  La cuenta corriente que se paga.
     * @param  array|null  $filas  Array crudo `current_acount_payment_methods` del request.
     * @param  array|null  $to_pay  El `to_pay` del request (`['id' => <débito>]`) o null.
     * @return array  `[mensaje_de_error|null, filas_normalizadas]`. Con error, las filas son las
     *                originales; sin error, las normalizadas (o lo que vino, si no era un array).
     */
    static function validar_y_normalizar($credit_account_id, $filas, $to_pay = null) {

        $moneda_de_la_cuenta = self::moneda_de_la_cuenta($credit_account_id);

        // Sin cuenta no hay moneda contra la cual validar: se deja el flujo como estaba.
        if (is_null($moneda_de_la_cuenta) || !is_array($filas)) {

            return [null, $filas];
        }

        /** Cajas ya leídas en esta validación, por id (varias filas pueden apuntar a la misma). */
        $cajas = [];

        /** Filas normalizadas, con las mismas claves (posiciones) que las que vinieron. */
        $normalizadas = [];

        foreach ($filas as $indice => $fila) {

            // Un elemento que no es una fila (basura del payload) no se toca ni se valida: el alta
            // hace con él lo que hacía hasta ahora.
            if (!is_array($fila)) {

                $normalizadas[$indice] = $fila;
                continue;
            }

            $amount = self::numero(isset($fila['amount']) ? $fila['amount'] : null);

            // Un monto negativo se saltea en la validación de aperturas de caja y en
            // `deberia_haber_impactado_caja()`, pero `attach_payment_methods()` lo adjunta igual:
            // dejaría un ingreso NEGATIVO en la caja y AUMENTARÍA la deuda de la cuenta.
            if ($amount < 0) {

                return [
                    'Un monto de pago no puede ser negativo: la '.self::descripcion_de_la_fila($fila, $indice).' tiene '.self::formato($amount).'.',
                    $filas,
                ];
            }

            // Sin monto (vacío o 0) no se adjunta, no mueve caja ni cuenta: no hay nada que validar.
            if ($amount == 0) {

                $normalizadas[$indice] = $fila;
                continue;
            }

            $moneda_de_la_fila = self::moneda_de_la_fila($fila, $moneda_de_la_cuenta);

            if ($moneda_de_la_fila == $moneda_de_la_cuenta) {

                // Fila en la moneda de la cuenta: vale su `amount`. Cualquier cotización o cotizado
                // que traiga es un residuo (el front mismo avisa, en `PaymentMethodsStep.vue::
                // completar()`, que un cotizado que queda de otra moneda "duplica el total repartido").
                $fila['amount_cotizado'] = null;
                $fila['cotizacion'] = null;

            } else {

                $cotizacion = self::numero(isset($fila['cotizacion']) ? $fila['cotizacion'] : null);

                // Sin cotización no hay "monto correcto": un pago de 120000 ARS sobre una deuda en
                // dólares no puede valer 120000 dólares ni 0.
                //
                // 🔴 Y una cotización de 1 (o menos) tampoco sirve: entre pesos y dólares significaría
                // "un peso = un dólar", que es justo lo que queda cuando la cotización no se cargó y la
                // cuenta la completa con un 1. Es consistente consigo misma (`amount / 1 = amount`), así
                // que el chequeo de "no cierra" de más abajo no la ve, pero el efecto es el mismo que el
                // de no tener cotización. Caso real en producción (2R, 14/8/2026, Pago N°303): una fila
                // de $126.900 con cotización 1,00 sobre la cuenta en dólares de un cliente acreditó
                // USD 126.900 (debía cerca de USD 82) y lo dejó con -126.899,97 USD a favor.
                if ($cotizacion <= self::COTIZACION_MINIMA) {

                    return [
                        'La '.self::descripcion_de_la_fila($fila, $indice).' está en '.self::nombre_de_moneda($moneda_de_la_fila)
                        .' y la cuenta que se paga está en '.self::nombre_de_moneda($moneda_de_la_cuenta)
                        .': hace falta una cotización del dólar mayor a '.self::COTIZACION_MINIMA.' (llegó '.self::formato($cotizacion).').',
                        $filas,
                    ];
                }

                $esperado = self::monto_cotizado($amount, $cotizacion, $moneda_de_la_fila);

                $recibido = self::numero(isset($fila['amount_cotizado']) ? $fila['amount_cotizado'] : null);

                if ($recibido != 0 && abs($recibido - $esperado) > max(self::TOLERANCIA_MINIMA, self::TOLERANCIA_RELATIVA * $esperado)) {

                    // El cotizado que mandó el front no cierra con lo que entregó y la cotización que
                    // él mismo declaró: no se sabe cuál de los tres números está mal, así que no se
                    // adivina. Si se guardara el cotizado, un cliente que debe 100 quedaría con 400 a favor.
                    return [
                        'La '.self::descripcion_de_la_fila($fila, $indice).' no cierra: '.self::formato($amount).' en '
                        .self::nombre_de_moneda($moneda_de_la_fila).' a una cotización de '.self::formato($cotizacion)
                        .' son '.self::formato($esperado).' en '.self::nombre_de_moneda($moneda_de_la_cuenta)
                        .', pero el monto cotizado que llegó es '.self::formato($recibido).'.',
                        $filas,
                    ];
                }

                // El cotizado que se guarda es SIEMPRE el que sale de la cuenta del back (`esperado`,
                // redondeado a 2 decimales como la columna), no el que mandó el front: si vino vacío
                // (una SPA o PWA vieja que no lo calculaba) se completa, y si vino dentro de la
                // tolerancia se lo pisa. Conservar el del front dejaba pasar hasta 0,1 % de deriva
                // en el haber (con un esperado de 1.000.000, hasta 1.000 de más), y el back tiene que
                // ser la autoridad del número que mueve el saldo.
                $fila['amount_cotizado'] = $esperado;
            }

            $error_de_caja = self::error_de_caja($fila, $indice, $moneda_de_la_fila, $cajas);

            if (!is_null($error_de_caja)) {

                return [$error_de_caja, $filas];
            }

            $normalizadas[$indice] = $fila;
        }

        $error_de_to_pay = self::error_de_to_pay($credit_account_id, $to_pay);

        if (!is_null($error_de_to_pay)) {

            return [$error_de_to_pay, $filas];
        }

        return [null, $normalizadas];
    }

    /**
     * Cuánto vale, en la moneda de la CUENTA, una fila de medio de pago. Es la regla que usa
     * `CurrentAcountPagoAltaHelper::get_haber()` para armar el haber, así que vale para todos los
     * caminos que registran pagos, pasen o no por `validar_y_normalizar()`.
     *
     *  - Sin moneda de la cuenta (cuenta inexistente): el comportamiento de siempre, el cotizado si
     *    vino con valor y si no el monto.
     *  - Fila en la moneda de la cuenta: su `amount`. Un cotizado residual no cuenta.
     *  - Fila cruzada: su `amount_cotizado`; si falta, se calcula con la cotización de la fila.
     *  - Fila cruzada sin cotizado ni cotización usable: no hay forma de convertirla, así que vale su
     *    `amount` como hasta ahora. Rechazarla es del controlador (`validar_y_normalizar()`); esta
     *    función solo suma y no puede rebotar nada.
     *
     * @param  array  $fila  Fila de `current_acount_payment_methods`.
     * @param  int|null  $moneda_de_la_cuenta  Moneda normalizada de la cuenta, o null si no se conoce.
     * @return float
     */
    static function valor_de_la_fila_en_la_cuenta($fila, $moneda_de_la_cuenta) {

        $amount = self::numero(isset($fila['amount']) ? $fila['amount'] : null);
        $cotizado = self::numero(isset($fila['amount_cotizado']) ? $fila['amount_cotizado'] : null);

        if (is_null($moneda_de_la_cuenta)) {

            return $cotizado > 0 ? $cotizado : $amount;
        }

        // Una fila sin monto no tiene efecto economico: ni el cotizado ni la cotizacion que le queden
        // de un estado viejo cuentan.
        if ($amount <= 0) {

            return 0.0;
        }

        $moneda_de_la_fila = self::moneda_de_la_fila($fila, $moneda_de_la_cuenta);

        if ($moneda_de_la_fila == $moneda_de_la_cuenta) {

            return $amount;
        }

        if ($cotizado > 0) {

            return $cotizado;
        }

        $cotizacion = self::numero(isset($fila['cotizacion']) ? $fila['cotizacion'] : null);

        if ($cotizacion > 0) {

            return self::monto_cotizado($amount, $cotizacion, $moneda_de_la_fila);
        }

        return $amount;
    }

    /**
     * La moneda de la cuenta corriente, normalizada (0 o null valen pesos, como en el resto del
     * sistema: `CurrentAcountFromSaleHelper`, `LimiteCreditoHelper`).
     *
     * @param  int|string|null  $credit_account_id
     * @return int|null  null si la cuenta no existe.
     */
    static function moneda_de_la_cuenta($credit_account_id) {

        if (is_null($credit_account_id) || $credit_account_id === '') {

            return null;
        }

        $credit_account = CreditAccount::find($credit_account_id);

        if (is_null($credit_account)) {

            return null;
        }

        return self::normalizar_moneda($credit_account->moneda_id);
    }

    /**
     * La moneda en la que está una fila. Vacía, 0 o null (una integración vieja que no conoce las
     * monedas) es la moneda de la cuenta: la fila vale su `amount`, entra a su caja y no cotiza.
     *
     * @param  array  $fila
     * @param  int  $moneda_de_la_cuenta
     * @return int
     */
    static function moneda_de_la_fila($fila, $moneda_de_la_cuenta) {

        $moneda = (int) self::numero(isset($fila['moneda_id']) ? $fila['moneda_id'] : null);

        return $moneda > 0 ? $moneda : $moneda_de_la_cuenta;
    }

    /**
     * Una moneda de la base como el entero que el sistema entiende: 0, null o vacío es pesos.
     *
     * @param  mixed  $moneda_id
     * @return int
     */
    static function normalizar_moneda($moneda_id) {

        $moneda = (int) self::numero($moneda_id);

        return $moneda > 0 ? $moneda : self::MONEDA_PESOS;
    }

    /**
     * El monto de una fila cruzada en la moneda de la cuenta. Es la cuenta de
     * `PaymentMethodsStep.vue::check_moneda()`: la fila en pesos se divide por la cotización y la
     * fila en cualquier otra moneda se multiplica. Redondeado a 2 decimales, como se guarda.
     *
     * @param  float  $amount  Monto de la fila, en SU moneda.
     * @param  float  $cotizacion  Mayor a 0.
     * @param  int  $moneda_de_la_fila
     * @return float
     */
    static function monto_cotizado($amount, $cotizacion, $moneda_de_la_fila) {

        if ($moneda_de_la_fila == self::MONEDA_PESOS) {

            return round($amount / $cotizacion, 2);
        }

        return round($amount * $cotizacion, 2);
    }

    /**
     * El 422 de la caja de una fila, o null si la caja sirve.
     *
     * Una caja es de UNA moneda (`cajas.moneda_id`) y recibe el `amount` de la fila tal cual, sin
     * convertir: una fila en dólares apuntando a una caja en pesos dejaría "100" pesos en la caja y
     * el arqueo no cerraría nunca. Una caja sin moneda (null o 0, datos de antes de que las cajas
     * tuvieran moneda) no restringe nada. Una retención no entra a ninguna caja aunque traiga una
     * (`CurrentAcountPagoHelper::nunca_impacta_caja()`), así que su caja no se valida.
     *
     * @param  array  $fila
     * @param  int|string  $indice  Posición de la fila, para el mensaje.
     * @param  int  $moneda_de_la_fila  Moneda efectiva de la fila.
     * @param  array  $cajas  Cajas ya leídas, por id. Se completa por referencia.
     * @return string|null
     */
    protected static function error_de_caja($fila, $indice, $moneda_de_la_fila, array &$cajas) {

        $caja_id = (int) self::numero(isset($fila['caja_id']) ? $fila['caja_id'] : null);

        if ($caja_id <= 0) {

            return null;
        }

        if (self::es_una_retencion($fila)) {

            return null;
        }

        if (!array_key_exists($caja_id, $cajas)) {

            $cajas[$caja_id] = Caja::find($caja_id);
        }

        $caja = $cajas[$caja_id];

        // Una caja inexistente ya la resuelven los helpers de abajo como siempre.
        if (is_null($caja)) {

            return null;
        }

        $moneda_de_la_caja = (int) self::numero($caja->moneda_id);

        if ($moneda_de_la_caja <= 0 || $moneda_de_la_caja == $moneda_de_la_fila) {

            return null;
        }

        $nombre_de_la_caja = $caja->name ? $caja->name : ('Caja N° '.$caja->num);

        return 'La caja "'.$nombre_de_la_caja.'" es en '.self::nombre_de_moneda($moneda_de_la_caja)
                .' y la '.self::descripcion_de_la_fila($fila, $indice).' está en '.self::nombre_de_moneda($moneda_de_la_fila)
                .': una caja solo recibe plata de su misma moneda. Elegí una caja en '.self::nombre_de_moneda($moneda_de_la_fila).'.';
    }

    /**
     * El 422 de un `to_pay` que apunta a un débito de OTRA cuenta corriente, o null.
     *
     * El `to_pay` dirige el pago a UN débito y el sobrante sigue en orden en la MISMA cuenta.
     * Pesos no saldan dólares sin cotizar: si el débito elegido es de la cuenta en otra moneda, el
     * pago le imputaría un monto que no está en su moneda (`CurrentAcountPagoHelper::setSinPagar()`
     * lo resolvía con un `CurrentAcount::find()` sin mirar la cuenta). Un débito que no existe no se
     * rechaza acá: el alta lo ignora y el pago entra en orden.
     *
     * Lee el débito igual que `CurrentAcountCuotaHelper::get_to_pay_id()`: `to_pay['id']`.
     *
     * @param  int|string  $credit_account_id  La cuenta que se paga.
     * @param  mixed  $to_pay
     * @return string|null
     */
    protected static function error_de_to_pay($credit_account_id, $to_pay) {

        if (!is_array($to_pay) || !isset($to_pay['id']) || !is_numeric($to_pay['id'])) {

            return null;
        }

        $debito = CurrentAcount::find($to_pay['id']);

        if (is_null($debito) || (int) $debito->credit_account_id == (int) $credit_account_id) {

            return null;
        }

        return 'El comprobante al que se quiere imputar el pago pertenece a otra cuenta corriente (de otra moneda). '
                .'Un pago solo puede saldar comprobantes de la cuenta que se está pagando.';
    }

    /**
     * Si la fila es de un método de pago tipo retención (no entra a ninguna caja).
     *
     * @param  array  $fila
     * @return bool
     */
    protected static function es_una_retencion($fila) {

        if (!isset($fila['current_acount_payment_method_id'])) {

            return false;
        }

        $metodo = CurrentAcountPaymentMethod::find($fila['current_acount_payment_method_id']);

        return !is_null($metodo) && !is_null($metodo->type) && $metodo->type->slug == 'retencion';
    }

    /**
     * Cómo nombrar una fila en un mensaje: su posición, y el método de pago si se lo conoce.
     * "fila 2 (Efectivo)". La posición es la de la lista que vio la persona en el modal.
     *
     * @param  array  $fila
     * @param  int|string  $indice  Posición base 0 en el array.
     * @return string
     */
    protected static function descripcion_de_la_fila($fila, $indice) {

        $descripcion = 'fila '.((int) $indice + 1);

        if (isset($fila['current_acount_payment_method_id'])) {

            $metodo = CurrentAcountPaymentMethod::find($fila['current_acount_payment_method_id']);

            if (!is_null($metodo) && $metodo->name) {

                $descripcion .= ' ('.$metodo->name.')';
            }
        }

        return $descripcion;
    }

    /**
     * El nombre de una moneda para un mensaje ("Peso", "Dolar"), o "moneda N" si no está en la tabla.
     *
     * @param  int  $moneda_id
     * @return string
     */
    protected static function nombre_de_moneda($moneda_id) {

        $moneda = Moneda::find($moneda_id);

        return !is_null($moneda) && $moneda->name ? $moneda->name : ('moneda '.$moneda_id);
    }

    /**
     * Un número para un mensaje: hasta 2 decimales, sin ceros de más.
     *
     * @param  float  $valor
     * @return string
     */
    protected static function formato($valor) {

        return rtrim(rtrim(number_format((float) $valor, 2, '.', ''), '0'), '.');
    }

    /**
     * Un valor del payload como número. Vacío, null, texto o cualquier cosa no numérica es 0: la
     * misma lectura que el resto del alta (`(float) $payment_method['amount']`).
     *
     * @param  mixed  $valor
     * @return float
     */
    protected static function numero($valor) {

        if (is_null($valor) || is_bool($valor) || is_array($valor) || is_object($valor)) {

            return 0.0;
        }

        // is_finite: un JSON `1e999` llega como INF y `is_numeric(INF)` es true; con una cotizacion INF
        // el cotizado quedaba INF y el alta moria con un 500 en vez de un 422.
        return is_numeric($valor) && is_finite((float) $valor) ? (float) $valor : 0.0;
    }
}
