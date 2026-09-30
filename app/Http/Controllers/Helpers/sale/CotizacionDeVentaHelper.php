<?php

namespace App\Http\Controllers\Helpers\sale;

use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * La cotización del dólar de una venta: cuándo hace falta, cuándo es válida y con cuál se cuenta
 * cuando la de la venta no sirve (misión corregir-ventas-en-dolares, 30/9/2026).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  POR QUÉ EXISTE
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  `SaleHelper::getCost()` convierte el costo de cada renglón a la moneda de la venta con
 *  `sales.valor_dolar`, y hasta esta misión nada miraba que ese número existiera:
 *
 *    - venta en dólares SIN valor_dolar y con un artículo en pesos: `$cost /= (float) null` →
 *      "Division by zero" → HTTP 500 con un mensaje que no dice qué falta.
 *    - venta en pesos SIN valor_dolar y con un artículo en dólares: `$cost *= (float) null` → el
 *      costo del renglón se guardaba en CERO, sin error, y la ganancia pasaba a ser el precio
 *      entero. Peor que un 500: la venta se guarda mal y nadie se entera.
 *    - venta en dólares con un renglón al que le faltaba la clave `cost_in_dollars`:
 *      "Undefined index" → 500.
 *
 *  La SPA siempre manda las dos cosas (`valor_dolar` arranca en `owner.dollar` y el artículo entero
 *  trae `cost_in_dollars`), así que los dispara un payload directo, el asistente de IA, una PWA con
 *  datos viejos o un dueño sin dólar configurado.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  LAS DOS DEFENSAS, Y POR QUÉ SON DOS
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  1. `validar_venta_nueva()` — el 422 de `SaleController::store()`, ANTES de abrir la transacción.
 *     Es la que le dice al vendedor qué falta. Una venta en dólares sin cotización tampoco se puede
 *     facturar (ARCA recibe `Moneda_cotiz` = null), ni cobrar en pesos contra su cuenta, así que
 *     rechazarla es lo correcto y no solo lo prolijo. Solo rechaza ventas EN DÓLARES: una venta en
 *     pesos con un artículo en dólares y sin cotización sigue el camino de la defensa 2.
 *
 *  2. `resolver_para_costo()` — la red de `getCost()`. Ese método NO solo lo llama `store()`: lo
 *     llaman también confirmar un presupuesto, `BudgetHelper`, el asistente de IA y
 *     `SaleModificationsHelper`, y ninguno de esos caminos pasa por el 422 de `store()`. Ahí no se
 *     puede rechazar nada, así que se cuenta con el dólar del dueño (`users.dollar`) y, si tampoco
 *     hay, el costo se deja como está en vez de dividir por cero o multiplicar por cero.
 *
 *  Un caso distinto de "el dólar no viaja" es "el dólar viaja pero el renglón no dice en qué moneda
 *  está su costo": eso lo resuelve `item_esta_en_dolares()` / `item_esta_en_pesos()`, y no se
 *  rechaza (un artículo sin marca es un artículo en pesos, como siempre).
 *
 * PHP 7.4: sin match, str_contains, ?->, argumentos nombrados ni union types.
 */
class CotizacionDeVentaHelper
{
    /** Id de la moneda dólar (`monedas.id`). Pesos es 1. */
    const MONEDA_DOLAR = 2;

    /**
     * ¿Sirve este valor como cotización del dólar? Numérico, finito y mayor a cero.
     *
     * Acepta el número o el string numérico ("1234.56": lo que devuelve Eloquent para una columna
     * DECIMAL). Rechaza null, '', 0, negativos, texto ("1234,56" con coma no es numérico para PHP)
     * e infinitos (un JSON `1e999` llega como INF y `is_numeric(INF)` es true).
     *
     * @param  mixed  $valor
     * @return bool
     */
    static function es_cotizacion_valida($valor)
    {
        if (!is_numeric($valor)) {
            return false;
        }

        $numero = (float) $valor;

        return is_finite($numero) && $numero > 0;
    }

    /**
     * La moneda de una venta nueva: la que viaja si es un id válido y, en cualquier otro caso
     * (ausente, null, 0, '' o texto), pesos.
     *
     * Antes solo el null caía a pesos y el 0 se guardaba como moneda 0: una venta sin moneda que no
     * entraba en ninguna de las dos ramas de `getCost()` (ni convertía el costo) y que
     * `LimiteCreditoHelper` buscaba en una cuenta de "moneda 0" que no existe, salteándose el tope.
     *
     * @param  mixed  $moneda_id
     * @return int
     */
    static function normalizar_moneda_id($moneda_id)
    {
        if (is_numeric($moneda_id) && (int) $moneda_id > 0) {
            return (int) $moneda_id;
        }

        return 1;
    }

    /**
     * El `valor_dolar` que se persiste en `sales.valor_dolar`: la cotización redondeada a 2
     * decimales (los mismos de la columna DECIMAL(20,2)) o null si no sirve.
     *
     * Se redondea acá y no solo en la base para que el costo que `getCost()` calcula en memoria con
     * `$sale->valor_dolar` use EXACTAMENTE el valor que queda guardado y que después lee ARCA:
     * si la venta guardara 1234,57 y el costo se hubiera calculado con 1234,5678, ninguna cuenta
     * posterior (costo en dólares × valor_dolar de la venta) cerraría con el pivot.
     *
     * Un valor que no sirve (0, negativo, texto) se guarda como NULL y no como el número que vino:
     * 0 y NULL significan lo mismo para todo el que lee la columna, y un texto en una columna
     * numérica termina en un 500 en modo estricto.
     *
     * @param  mixed  $valor
     * @return float|null
     */
    static function valor_dolar_para_guardar($valor)
    {
        if (!self::es_cotizacion_valida($valor)) {
            return null;
        }

        return round((float) $valor, 2);
    }

    /**
     * ¿El costo de este renglón está cargado en dólares? Es la misma condición que la rama de
     * pesos de `getCost()` usaba inline (`isset($item['cost_in_dollars']) && == 1`), sacada acá
     * para que el 422 y el costo hablen exactamente de lo mismo: 1, '1' y true son dólares.
     *
     * @param  array|mixed  $item  El renglón como llega en `items[]`.
     * @return bool
     */
    static function item_esta_en_dolares($item)
    {
        if (!is_array($item)) {
            return false;
        }

        return isset($item['cost_in_dollars']) && $item['cost_in_dollars'] == 1;
    }

    /**
     * ¿El costo de este renglón está en pesos? Todo lo que no es dólares en la rama de dólares de
     * `getCost()`: sin la clave, con la clave en null, 0 o '0'.
     *
     * 🔴 `isset()` y no leer la clave pelada: la rama de dólares de `getCost()` hacía
     * `$item['cost_in_dollars'] == 0` sin `isset` y un renglón armado a mano sin la clave moría con
     * "Undefined index" (HTTP 500). La rama de pesos, en cambio, siempre usó `isset`. Un artículo
     * sin la marca es un artículo en pesos.
     *
     * @param  array|mixed  $item
     * @return bool
     */
    static function item_esta_en_pesos($item)
    {
        if (!is_array($item)) {
            return true;
        }

        return !isset($item['cost_in_dollars']) || $item['cost_in_dollars'] == 0;
    }

    /**
     * El 422 de una venta NUEVA que necesita cotización y no la trae, o null si puede seguir.
     *
     * "Necesita cotización" es, exactamente: la venta es en dólares (`moneda_id` = 2). Sin cotización
     * no hay costo convertido, ni factura (ARCA recibe `Moneda_cotiz` = null), ni cuenta contra la que
     * cobrar en pesos.
     *
     * 🔴 Una venta en PESOS NUNCA se rechaza por esto, ni siquiera con un artículo cargado en dólares
     * y sin `valor_dolar`: ese caso lo cubre la red de `getCost()` (`resolver_para_costo()`), que
     * cuenta con el dólar del dueño. La primera versión de esta misión la rechazaba también, y
     * rompía al asistente de IA (arma ventas en pesos con `valor_dolar` null y artículos en dólares,
     * que hasta acá se guardaban con costo 0): un 422 sobre un camino que no puede pedirle nada al
     * usuario es peor que un costo calculado con el dólar del dueño. Una venta en pesos sin artículos
     * en dólares (la enorme mayoría) tampoco la necesita: `valor_dolar` puede venir null o en cero.
     *
     * Sin queries: solo mira el request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array|null  El cuerpo del 422 (`message` + `sin_cotizacion_dolar`), o null.
     */
    static function validar_venta_nueva($request)
    {
        if (self::es_cotizacion_valida($request->valor_dolar)) {
            return null;
        }

        // La moneda que se va a guardar (el 0 y el null cuentan como pesos), no la cruda del request.
        $moneda_id = self::normalizar_moneda_id($request->moneda_id);

        if ($moneda_id == self::MONEDA_DOLAR) {

            return [
                'message'               => 'La venta necesita la cotización del dólar: se guarda en dólares y la cotización no llegó (o vino en cero). Cargá el valor del dólar y volvé a guardarla.',
                'sin_cotizacion_dolar'  => true,
            ];
        }

        return null;
    }

    /**
     * La cotización con la que `getCost()` convierte el costo de un renglón.
     *
     * Orden: la de la venta (`valor_dolar`, si sirve) → el dólar del dueño (`users.dollar`, si
     * sirve; con un warning, porque la venta quedó sin cotización propia) → null.
     *
     * Con null el que llama NO tiene que dividir ni multiplicar: deja el costo como está. Es la
     * única salida que no inventa un número (dividir por cero es un 500; multiplicar por cero
     * guarda un costo en cero sin avisar). También se loguea, para que el motivo de un costo sin
     * convertir aparezca en el log y no haya que reconstruirlo.
     *
     * `$sale` puede ser una venta o un presupuesto (`BudgetHelper` llama a `getCost()` con un
     * `Budget`): los dos tienen `valor_dolar`, `user_id` e `id`. Pero `Budget` NO tiene la relacion
     * `user()` (`getCost()` hace `$sale->user`, que en un presupuesto es null), asi que cuando el que
     * llama no trae al dueño se lo busca por `user_id`: sin esto la red del dolar del dueño estaba
     * muerta para los presupuestos.
     *
     * @param  \App\Models\Sale|\App\Models\Budget  $sale
     * @param  \App\Models\User|null  $user  El dueño de la venta (`$sale->user`).
     * @return float|null
     */
    static function resolver_para_costo($sale, $user)
    {
        if (is_null($user) && !empty($sale->user_id)) {

            $user = User::find($sale->user_id);
        }

        if (self::es_cotizacion_valida($sale->valor_dolar)) {
            return (float) $sale->valor_dolar;
        }

        if (!is_null($user) && self::es_cotizacion_valida($user->dollar)) {

            Log::warning('getCost: '.get_class($sale).' '.$sale->id.' sin valor_dolar valido ('.var_export($sale->valor_dolar, true).'); se usa el dolar del dueño ('.$user->dollar.').');

            return (float) $user->dollar;
        }

        Log::warning('getCost: '.get_class($sale).' '.$sale->id.' sin valor_dolar valido ('.var_export($sale->valor_dolar, true).') y el dueño no tiene dolar cargado; el costo queda sin convertir.');

        return null;
    }
}
