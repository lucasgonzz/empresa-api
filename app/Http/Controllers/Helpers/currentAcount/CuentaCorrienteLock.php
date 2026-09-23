<?php

namespace App\Http\Controllers\Helpers\currentAcount;

use App\Models\CreditAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Candado por cuenta corriente (misión cuenta-corriente-carrera-y-velocidad, 23/9/2026).
 *
 * 🔴 POR QUÉ EXISTE. En Fenix, el 23/9/2026, dos PCs editaron a la vez dos ventas del mismo
 * cliente y la cadena de saldos quedó corta por $28.381,20 (venta 54160, informe
 * `_cruzado/misiones/20260923-fenix-saldo-cc-venta-54160/informe.md`). MySQL corre en
 * REPEATABLE READ: cada request recalculaba la cadena con SELECT comunes, que ven la foto de la base
 * tomada en la primera lectura de SU transacción, y nada impedía que dos requests escribieran la
 * misma cuenta al mismo tiempo. El que quedó bloqueado en otro lado siguió con su foto vieja y
 * escribió saldos calculados sobre totales que ya no existían.
 *
 * Este candado SERIALIZA las escrituras sobre la cuenta corriente de un dueño: el segundo request
 * espera acá hasta el commit del primero, y como la espera es ANTES de cualquier lectura común, su
 * foto se toma después del commit del otro.
 *
 * QUÉ SE BLOQUEA. La fila del DUEÑO de la cuenta (`clients` o `providers`), no la de
 * `credit_accounts`: es la fila que siempre existe (la cuenta en dólares puede no haberse creado
 * todavía) y una sola cubre todas las monedas. No es un candado nuevo sobre esa fila: todo camino que
 * escribe la cuenta corriente ya termina escribiéndola (`CurrentAcountHelper::set_model_saldo()` le
 * guarda `saldo_pesos`/`saldo_dolares`, `CurrentAcountPagoHelper::setModelPagosCheckeados()` le
 * guarda `pagos_checkeados`), o sea que ya se la bloqueaba con un X-lock, solo que al final. Lo que
 * cambia es el momento: al principio, antes de leer nada.
 *
 * EL ORDEN. Cuando un request toca más de un dueño (una edición de venta que le cambia el cliente),
 * los ids se bloquean en orden ascendente, siempre, para que dos requests que se crucen no se
 * esperen en círculo. Y respecto de las otras filas: la venta (si el camino la bloquea) va ANTES que
 * la cuenta; el stock y los renglones, DESPUÉS.
 *
 * 🔴 SOLO TIENE EFECTO ADENTRO DE UNA TRANSACCIÓN. En autocommit, un SELECT ... FOR UPDATE suelta el
 * candado al terminar la sentencia: no protege nada. Fuera de una transacción esto loguea un warning
 * (salvo en consola: seeders y comandos no lo necesitan) y no hace nada, sin romper el llamador.
 *
 * 🔴 Y TIENE QUE SER LA PRIMERA SENTENCIA DE LA TRANSACCIÓN, o lo más cerca posible. Una lectura
 * común antes del candado fija la foto de la base antes de esperar, y la espera pierde la mitad de
 * su sentido. Por eso el dueño se recibe como parámetro y no se lee de `credit_accounts` acá
 * adentro: el que tiene sólo el id de la cuenta lo resuelve ANTES de abrir la transacción, con
 * `duenio_de_la_cuenta()`. Llamarlo dos veces en la misma transacción es gratis: una fila que la
 * transacción ya tiene bloqueada se vuelve a bloquear sin esperar.
 */
class CuentaCorrienteLock {

    /**
     * Tabla del dueño de la cuenta según `credit_accounts.model_name`.
     *
     * @var array<string,string>
     */
    const TABLAS = [
        'client'    => 'clients',
        'provider'  => 'providers',
    ];

    /**
     * Bloquea la cuenta corriente de uno o varios dueños del mismo tipo hasta el commit o el
     * rollback de la transacción abierta.
     *
     * @param  string            $model_name  'client' o 'provider'.
     * @param  int|array|null    $model_ids   Un id o varios. Los null, vacíos y repetidos se ignoran.
     * @return bool  true si tomó el candado (o no había nada que bloquear), false si no hay transacción.
     */
    static function bloquear($model_name, $model_ids) {

        if (!isset(self::TABLAS[$model_name])) {

            // Un model_name que no es cliente ni proveedor no tiene cuenta que bloquear. No se
            // rompe: el llamador sigue como antes de que existiera este candado.
            Log::warning('CuentaCorrienteLock: model_name desconocido "'.$model_name.'". No se bloquea nada.');
            return false;
        }

        $ids = self::ids_ordenados($model_ids);

        if (count($ids) == 0) {
            return true;
        }

        if (DB::transactionLevel() == 0) {

            if (!app()->runningInConsole()) {
                Log::warning('CuentaCorrienteLock: se pidió bloquear la cuenta de '.$model_name.' '.implode(',', $ids).' fuera de una transacción. No se bloquea nada: en autocommit el candado se suelta al terminar la sentencia.');
            }

            return false;
        }

        $tabla = self::TABLAS[$model_name];

        // Uno por uno y en orden ascendente: el orden de adquisición es lo que evita el deadlock
        // entre dos requests que bloquean los mismos dos dueños.
        foreach ($ids as $id) {

            DB::table($tabla)
                ->where('id', $id)
                ->lockForUpdate()
                ->first(['id']);
        }

        return true;
    }

    /**
     * Bloquea la cuenta del dueño de una `credit_account`, con el dueño ya resuelto.
     *
     * @param  array|null  $duenio  Lo que devuelve duenio_de_la_cuenta(), o null.
     * @return bool
     */
    static function bloquear_duenio($duenio) {

        if (is_null($duenio)) {
            return true;
        }

        return self::bloquear($duenio['model_name'], $duenio['model_id']);
    }

    /**
     * El dueño de una cuenta corriente: `['model_name' => ..., 'model_id' => ...]`, o null si la
     * cuenta no existe.
     *
     * 🔴 Es una lectura COMÚN. Llamala ANTES de abrir la transacción: adentro fijaría la foto de la
     * base antes de esperar el candado (ver el docblock de la clase). El dueño de una cuenta no
     * cambia nunca, así que leerlo afuera no tiene carrera.
     *
     * @param  int|null  $credit_account_id
     * @return array|null
     */
    static function duenio_de_la_cuenta($credit_account_id) {

        if (is_null($credit_account_id)) {
            return null;
        }

        $credit_account = CreditAccount::find($credit_account_id);

        if (is_null($credit_account)) {
            return null;
        }

        return [
            'model_name'    => $credit_account->model_name,
            'model_id'      => $credit_account->model_id,
        ];
    }

    /**
     * Ids enteros positivos, sin repetir, en orden ascendente.
     *
     * @param  int|array|null  $model_ids
     * @return array<int,int>
     */
    static function ids_ordenados($model_ids) {

        if (!is_array($model_ids)) {
            $model_ids = [$model_ids];
        }

        $ids = [];

        foreach ($model_ids as $id) {

            if (is_null($id) || $id === '' || (int) $id <= 0) {
                continue;
            }

            $ids[(int) $id] = (int) $id;
        }

        $ids = array_values($ids);

        sort($ids);

        return $ids;
    }
}
