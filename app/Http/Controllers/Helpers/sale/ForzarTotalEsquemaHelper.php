<?php

namespace App\Http\Controllers\Helpers\sale;

use Illuminate\Support\Facades\Schema;

/**
 * Guarda de esquema de `forzar_total_monto` (mision forzar-total-por-monto, 17/9/2026).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  🔴 POR QUE EXISTE, Y POR QUE ES MAS GRAVE QUE LA DE `budget_combo`
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  La columna la crean las migraciones `2026_09_17_100000` de esta misma mision, y **un deploy de
 *  empresa sube los archivos ANTES de migrar**: `DeploymentService::execute_steps()` de `admin-api`
 *  corre `upload_api` -> `sync_env_keys` -> `run_migrations`, en ese orden. Entre el primero y el
 *  tercero hay una ventana en la que el cliente tiene el codigo nuevo y no tiene la columna.
 *
 *  Y ahi el daño no se limita a "las ventas forzadas". `Sale` y `Budget` declaran `$guarded = []`,
 *  asi que Eloquent mete `forzar_total_monto` en el INSERT **aunque valga null**: sin esta guarda,
 *  en esa ventana se cae el alta de TODA venta y de TODO presupuesto, de todos los clientes, con
 *  `SQLSTATE[42S22]: Unknown column`. El mostrador entero parado.
 *
 *  La guarda de `budget_combo` (commit 5c0da889, 16/9/2026) tapa exactamente esta ventana en este
 *  mismo circuito, y su mensaje lo dice con todas las letras. Aquello dejaba sin presupuestos; esto
 *  deja sin vender. Por eso la guarda no es defensiva por las dudas: es el requisito para que este
 *  arreglo se pueda desplegar.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  QUE HACE CUANDO LA COLUMNA NO ESTA
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  Los cuatro puntos de escritura —`SaleController` alta y actualizacion, `BudgetController` alta y
 *  actualizacion— OMITEN la clave del payload. La venta se guarda sin el forzado, que es
 *  exactamente lo correcto: en esa ventana la SPA vieja ni siquiera lo manda, y la nueva todavia no
 *  esta desplegada. Nunca una excepcion. Mismo criterio que las guardas de `budget_combo`,
 *  `order_combo` y `buyer_tracking_events`: lo que puede no estar se chequea, y si no esta el
 *  camino sigue con el equivalente de "no hay nada".
 *
 *  La LECTURA no necesita esta guarda: `SaleHelper::get_forzar_total_monto()` usa `isset()`, que
 *  sobre un modelo sin el atributo devuelve false y corta en 0.
 *
 * ⚠️ MEMOIZADA, y por columna. `Schema::hasColumn()` son dos consultas a `information_schema` y los
 * caminos que la necesitan la preguntarian en cada alta y en cada actualizacion. Se pregunta una
 * sola vez por proceso y por tabla. Dos memos y no uno: las dos migraciones son la misma, pero una
 * base a medio migrar puede tener una columna y no la otra, y un memo compartido responderia por la
 * tabla equivocada — es el mismo motivo por el que `budget_combo` y `order_combo` son dos clases y
 * no una parametrizada.
 */
class ForzarTotalEsquemaHelper {

    /** La columna que puede no estar todavia. */
    const COLUMNA = 'forzar_total_monto';

    /**
     * Resultado memoizado de `Schema::hasColumn('sales', self::COLUMNA)`.
     *
     * `null` = todavia no se pregunto en este proceso.
     *
     * @var bool|null
     */
    private static $existe_en_sales = null;

    /**
     * Resultado memoizado de `Schema::hasColumn('budgets', self::COLUMNA)`.
     *
     * @var bool|null
     */
    private static $existe_en_budgets = null;

    /**
     * ¿La tabla `sales` de este cliente ya tiene la columna?
     *
     * @return bool
     */
    static function hay_columna_en_sales() {

        if (is_null(Self::$existe_en_sales)) {
            Self::$existe_en_sales = Schema::hasColumn('sales', Self::COLUMNA);
        }

        return Self::$existe_en_sales;
    }

    /**
     * ¿La tabla `budgets` de este cliente ya tiene la columna?
     *
     * @return bool
     */
    static function hay_columna_en_budgets() {

        if (is_null(Self::$existe_en_budgets)) {
            Self::$existe_en_budgets = Schema::hasColumn('budgets', Self::COLUMNA);
        }

        return Self::$existe_en_budgets;
    }

    /**
     * Borra la memoizacion de las dos tablas.
     *
     * La usa el test de la guarda, que necesita que la respuesta se vuelva a preguntar despues de
     * sacar y volver a poner la columna. Sin esto, el memo de un test se le filtraria a todos los
     * que corren despues en el mismo proceso de PHPUnit.
     *
     * @return void
     */
    static function olvidar() {
        Self::$existe_en_sales = null;
        Self::$existe_en_budgets = null;
    }

    /**
     * Mete `forzar_total_monto` en un payload de `create()` SOLO si la columna existe.
     *
     * Es el accesor que usan los cuatro puntos de escritura, para que la condicion viva en un solo
     * lugar y no se pueda olvidar en uno de ellos.
     *
     * @param  array       $payload  El array que va a `Sale::create()` / `Budget::create()`.
     * @param  float|null  $monto    Monto ya normalizado.
     * @param  string      $tabla    'sales' o 'budgets'.
     * @return array
     */
    static function agregar_al_payload($payload, $monto, $tabla) {

        if (Self::hay_columna($tabla)) {
            $payload[Self::COLUMNA] = $monto;
        }

        return $payload;
    }

    /**
     * ¿La tabla pedida tiene la columna?
     *
     * @param  string  $tabla  'sales' o 'budgets'.
     * @return bool
     */
    static function hay_columna($tabla) {

        if ($tabla === 'budgets') {
            return Self::hay_columna_en_budgets();
        }

        return Self::hay_columna_en_sales();
    }
}
