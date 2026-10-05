<?php

namespace App\Http\Controllers\Helpers\Budget;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Guarda de esquema de `budgets.selected_payment_methods` (misión
 * presupuesto-contado-o-cuenta-corriente, 1/10/2026).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  🔴 POR QUÉ EXISTE
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  La columna la crea la migración `2026_10_01_120000` de esta misma misión, y **un deploy de
 *  empresa sube los archivos ANTES de migrar**: `DeploymentService::execute_steps()` de `admin-api`
 *  corre `upload_api` -> `sync_env_keys` -> `run_migrations`, en ese orden. Entre el primero y el
 *  tercero hay una ventana en la que el cliente tiene el código nuevo y no tiene la columna.
 *
 *  Y `Budget` declara `$guarded = []`: Eloquent mete cada clave del array en el INSERT/UPDATE aunque
 *  valga null. Sin esta guarda, en esa ventana se cae el alta y la edición de TODO presupuesto de
 *  TODOS los clientes con `SQLSTATE[42S22]: Unknown column 'selected_payment_methods'`. Es el mismo
 *  agujero —y la misma solución— que `ForzarTotalEsquemaHelper` (forzar_total_monto, 17/9/2026) y
 *  `ComboEsquemaHelper` (budget_combo, 16/9/2026): lo que puede no estar se pregunta, y si no está
 *  el camino sigue con el equivalente de "no hay nada". Nunca una excepción.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  PUNTOS DE ESCRITURA (medidos el 1/10/2026)
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  Barrido que hay que rehacer si alguien agrega un camino nuevo:
 *
 *      grep -rnE "selected_payment_methods" app/    (y mirar cuáles escriben sobre `budgets`)
 *      grep -rnE "Budget::(create|forceCreate|insert|updateOrCreate)|new Budget\b|DB::table\('budgets'\)" app/ database/
 *
 *  De todos los resultados, los que ESCRIBEN la columna son DOS:
 *
 *    1. `BudgetController::store()`   — alta de presupuesto  (`agregar_al_payload()`)
 *    2. `BudgetController::update()`  — edición de presupuesto  (`asignar_al_modelo()`)
 *
 *  Los que NO la escriben, a propósito:
 *    - `BudgetDuplicarHelper::duplicate()`: el duplicado nace SIEMPRE a cuenta corriente y sin
 *      reparto (un cheque o un reparto son una operación de una sola vez). Como la columna no
 *      está en su array, un duplicado no pasa por esta guarda y no la necesita.
 *    - `BudgetHelper::saveSale()`: escribe `sales`, no `budgets`.
 *    - `SembrarDatosDePrueba`, `BudgetSeeder`, `PropuestaPresupuestoIaHelper`: no mandan la clave
 *      (el asistente de IA entra por `BudgetController::store()`, o sea por el punto 1).
 *
 *  La LECTURA no necesita guarda: `$budget->selected_payment_methods` sobre un modelo sin el
 *  atributo devuelve null (verificado con el cast `array` de `Budget`, ver el test 11), y null es
 *  "no hay reparto" = cuenta corriente.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  QUÉ HACE CUANDO LA COLUMNA NO ESTÁ
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  "De contado" es FALSO (`BudgetCobroHelper::es_de_contado*()` preguntan acá primero): el
 *  presupuesto se guarda exactamente como hoy, a cuenta corriente, y se deja un `Log::warning` si el
 *  request pedía cobro. La SPA lo detecta (el modelo vuelve con `omitir_en_cuenta_corriente` en 0) y
 *  avisa. Es el comportamiento correcto de esa ventana: la SPA nueva todavía no está desplegada.
 *
 * ⚠️ MEMOIZADA. `Schema::hasColumn()` es UNA consulta por tabla y los caminos que la necesitan la
 * preguntarían en cada alta, edición y confirmación. Se pregunta una sola vez por proceso. El memo
 * no sobrevive a la migración, pero no hace falta que lo haga: en PHP-FPM los statics mueren con el
 * request; el único caso que podría quedar clavado es un `queue:work` booteado DENTRO de la ventana,
 * y `DeploymentService::step_restart_queue_workers()` corre DESPUÉS de `run_migrations`. Si algún
 * día esos pasos se reordenan, esto hay que volver a mirarlo (mismo aviso que
 * `ForzarTotalEsquemaHelper`).
 */
class CobroPresupuestoEsquemaHelper {

    /** La columna que puede no estar todavía. */
    const COLUMNA = 'selected_payment_methods';

    /**
     * Resultado memoizado de `Schema::hasColumn('budgets', self::COLUMNA)`.
     *
     * `null` = todavía no se preguntó en este proceso.
     *
     * @var bool|null
     */
    private static $existe_en_budgets = null;

    /**
     * ¿La tabla `budgets` de este cliente ya tiene la columna?
     *
     * @return bool
     */
    static function hay_columna() {

        if (is_null(Self::$existe_en_budgets)) {
            Self::$existe_en_budgets = Schema::hasColumn('budgets', Self::COLUMNA);
        }

        return Self::$existe_en_budgets;
    }

    /**
     * Borra la memoización.
     *
     * La usa el test de la guarda, que necesita que la respuesta se vuelva a preguntar después de
     * sacar y volver a poner la columna. Sin esto, el memo de un test se le filtraría a todos los que
     * corren después en el mismo proceso de PHPUnit.
     *
     * @return void
     */
    static function olvidar() {
        Self::$existe_en_budgets = null;
    }

    /**
     * Mete `selected_payment_methods` en un payload de `Budget::create()` SOLO si la columna existe.
     *
     * Es el accesor del punto de escritura 1 (alta). La condición vive acá y no en el controlador
     * para que no se pueda olvidar.
     *
     * @param  array       $payload  El array que va a `Budget::create()`.
     * @param  array|null  $filas    El reparto ya normalizado, o null si el presupuesto va a cuenta
     *                               corriente (se escribe NULL: "sin reparto").
     * @return array
     */
    static function agregar_al_payload($payload, $filas) {

        if (Self::hay_columna()) {
            $payload[Self::COLUMNA] = $filas;
        }

        return $payload;
    }

    /**
     * Asigna `selected_payment_methods` a un modelo `Budget` ya cargado SOLO si la columna existe.
     *
     * Es el accesor del punto de escritura 2 (edición). Solo asigna: el `save()` lo hace el
     * controlador, junto con el resto de los campos.
     *
     * @param  \App\Models\Budget  $budget
     * @param  array|null          $filas   El reparto ya normalizado, o null para dejarlo sin reparto.
     * @return void
     */
    static function asignar_al_modelo($budget, $filas) {

        if (Self::hay_columna()) {
            $budget->{Self::COLUMNA} = $filas;
        }
    }

    /**
     * Deja dicho en el log que se pidió un cobro y la columna no está. Lo llaman los que deciden
     * "de contado" sobre un request, para que el aviso salga una sola vez por decisión y no en cada
     * lectura.
     *
     * @param  string  $donde  Quién lo avisa, para rastrearlo en el log.
     * @return void
     */
    static function avisar_columna_faltante($donde) {

        Log::warning($donde.': el presupuesto pide cobrarse de contado pero la tabla budgets todavia no tiene la columna selected_payment_methods (falta correr la migracion 2026_10_01_120000). Se guarda a cuenta corriente, como antes.');
    }
}
