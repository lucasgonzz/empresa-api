<?php

namespace App\Http\Controllers\Helpers;

use App\Models\CurrentAcountPaymentMethod;
use Illuminate\Support\Facades\Schema;

/**
 * Guarda de esquema de `payment_methods.current_acount_payment_method_id` (misión
 * tienda-cuotas-mercado-pago, 15/10/2026).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  🔴 POR QUÉ EXISTE
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  La columna la crea la migración `2026_10_15_100000` de esta misma misión, y **un deploy de
 *  empresa sube los archivos ANTES de migrar**: `DeploymentService::execute_steps()` de `admin-api`
 *  corre `upload_api` -> `sync_env_keys` -> `run_migrations`, en ese orden. Entre el primero y el
 *  tercero hay una ventana en la que el cliente tiene el código nuevo y no tiene la columna.
 *
 *  Y `PaymentMethod` declara `$guarded = []`: Eloquent mete cada clave del array en el INSERT/UPDATE
 *  aunque valga null. Sin esta guarda, en esa ventana se cae el alta y la edición de TODO método de
 *  pago de TODOS los clientes (`SQLSTATE[42S22]: Unknown column 'current_acount_payment_method_id'`),
 *  o sea ABM -> Tienda online -> Métodos de pago deja de andar justo cuando el dueño más lo mira.
 *  Es el mismo agujero —y la misma solución— que `CobroPresupuestoEsquemaHelper`
 *  (`budgets.selected_payment_methods`, 1/10/2026) y `ForzarTotalEsquemaHelper`: lo que puede no
 *  estar se pregunta, y si no está el camino sigue con el equivalente de "no hay nada". Nunca una
 *  excepción.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  PUNTOS DE ESCRITURA (medidos el 15/10/2026)
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  Barrido que hay que rehacer si alguien agrega un camino nuevo:
 *
 *      git grep -nE "current_acount_payment_method_id" -- app
 *      git grep -nE "PaymentMethod::(create|forceCreate|insert|updateOrCreate|firstOrCreate)|new PaymentMethod\b|DB::table\('payment_methods'\)" -- app database
 *
 *  De todos los resultados, los que ESCRIBEN la columna son DOS:
 *
 *    1. `PaymentMethodController::store()`   — alta del método  (`agregar_al_payload()`)
 *    2. `PaymentMethodController::update()`  — edición del método  (`asignar_al_modelo()`, y solo si
 *       el request trae el campo)
 *
 *  Los demás `PaymentMethod::create/firstOrCreate` de `app/` (el espejo del OAuth de Mercado Pago, el
 *  setup de usuarios y de demos) no mandan la clave: no pasan por esta guarda y no la necesitan.
 *
 *  La LECTURA no necesita guarda: `$payment_method->current_acount_payment_method_id` sobre un modelo
 *  sin el atributo devuelve null, y null es "sin vínculo" = la tienda sin cuotas, como siempre.
 *  Lo único que sí la necesita es la relación `PaymentMethod::current_acount_payment_method()`
 *  cuando se la usa con `with()` o `whereHas()`: tocar la columna en el SQL con la columna ausente
 *  revienta. Hoy nadie la eager-loadea.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  QUÉ HACE CUANDO LA COLUMNA NO ESTÁ
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  `PaymentMethodController` se comporta EXACTAMENTE como antes de esta misión: no escribe el campo,
 *  no lo valida y no lo menciona en ninguna consulta. La SPA nueva manda el campo igual; la API lo
 *  ignora hasta que la migración corra (y al correr, el dueño elige el vínculo en el ABM: no hay
 *  backfill).
 *
 * ⚠️ MEMOIZADA. `Schema::hasColumn()` es UNA consulta por tabla y el controller la preguntaría en cada
 * alta y edición. Se pregunta una sola vez por proceso. El memo no sobrevive a la migración, pero no
 * hace falta que lo haga: en PHP-FPM los statics mueren con el request; el único caso que podría
 * quedar clavado es un `queue:work` booteado DENTRO de la ventana, y
 * `DeploymentService::step_restart_queue_workers()` corre DESPUÉS de `run_migrations` (y el
 * controller no corre en una cola de todos modos). Si algún día esos pasos se reordenan, esto hay que
 * volver a mirarlo (mismo aviso que `CobroPresupuestoEsquemaHelper`).
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 */
class CuotasTiendaEsquemaHelper {

    /** La columna que puede no estar todavía. */
    const COLUMNA = 'current_acount_payment_method_id';

    /**
     * Resultado memoizado de `Schema::hasColumn('payment_methods', self::COLUMNA)`.
     *
     * `null` = todavía no se preguntó en este proceso.
     *
     * @var bool|null
     */
    private static $existe_en_payment_methods = null;

    /**
     * ¿La tabla `payment_methods` de este cliente ya tiene la columna del vínculo?
     *
     * @return bool
     */
    static function hay_columna() {

        if (is_null(Self::$existe_en_payment_methods)) {
            Self::$existe_en_payment_methods = Schema::hasColumn('payment_methods', Self::COLUMNA);
        }

        return Self::$existe_en_payment_methods;
    }

    /**
     * Borra la memoización.
     *
     * La usan los tests de la guarda: sin esto, el memo de un test se le filtraría a todos los que
     * corren después en el mismo proceso de PHPUnit.
     *
     * @return void
     */
    static function olvidar() {
        Self::$existe_en_payment_methods = null;
    }

    /**
     * ¿El valor significa "sin vínculo"?
     *
     * El select de la SPA ofrece «Seleccione ...» con valor 0 para no vincular, así que null, '', 0
     * y '0' quieren decir lo mismo.
     *
     * @param  mixed  $valor
     * @return bool
     */
    static function es_sin_vinculo($valor) {

        return is_null($valor) || $valor === '' || $valor === false || $valor === 0 || $valor === '0';
    }

    /**
     * Normaliza lo que manda la SPA a "un id positivo o null".
     *
     * Lo que no es un entero positivo (un 'abc', un -3) también da null: el que decide si eso se
     * rechaza es `es_un_vinculo_invalido()`, que mira el valor ANTES de normalizarlo.
     *
     * @param  mixed  $valor
     * @return int|null
     */
    static function normalizar($valor) {

        if (Self::es_sin_vinculo($valor) || !is_numeric($valor) || (int) $valor <= 0) {
            return null;
        }

        return (int) $valor;
    }

    /**
     * ¿El valor que llegó es un vínculo que no existe?
     *
     * Es el único caso que se RECHAZA (422): algo que no es "sin vínculo" y tampoco es el id de un
     * método de pago de cuenta corriente que exista. Ignorarlo lo convertiría en NULL y, en una
     * edición, borraría un vínculo bueno por un payload mal armado. Sin vínculo (0, '', null) no es
     * inválido: es desvincular.
     *
     * Sin la columna no se valida nada: el campo se ignora entero, como antes de la misión.
     *
     * @param  mixed  $valor  Lo que llegó en el request, sin normalizar.
     * @return bool
     */
    static function es_un_vinculo_invalido($valor) {

        if (!Self::hay_columna() || Self::es_sin_vinculo($valor)) {
            return false;
        }

        $id = Self::normalizar($valor);

        if (is_null($id)) {
            return true;
        }

        return !CurrentAcountPaymentMethod::where('id', $id)->exists();
    }

    /**
     * Mete el vínculo en un payload de `PaymentMethod::create()` SOLO si la columna existe.
     *
     * Es el accesor del punto de escritura 1 (alta). La condición vive acá y no en el controlador
     * para que no se pueda olvidar.
     *
     * @param  array  $payload  El array que va a `PaymentMethod::create()`.
     * @param  mixed  $valor    Lo que llegó en el request (0/''/null = sin vínculo).
     * @return array
     */
    static function agregar_al_payload($payload, $valor) {

        if (Self::hay_columna()) {
            $payload[Self::COLUMNA] = Self::normalizar($valor);
        }

        return $payload;
    }

    /**
     * Asigna el vínculo a un modelo `PaymentMethod` ya cargado SOLO si la columna existe.
     *
     * Es el accesor del punto de escritura 2 (edición). Solo asigna: el `save()` lo hace el
     * controlador, junto con el resto de los campos. 🔴 El controlador lo llama ÚNICAMENTE si el
     * request trae el campo (`has()`): una SPA vieja que no lo manda no puede borrar el vínculo.
     *
     * @param  \App\Models\PaymentMethod  $payment_method
     * @param  mixed                      $valor  Lo que llegó en el request (0/''/null = sin vínculo).
     * @return void
     */
    static function asignar_al_modelo($payment_method, $valor) {

        if (Self::hay_columna()) {
            $payment_method->{Self::COLUMNA} = Self::normalizar($valor);
        }
    }
}
