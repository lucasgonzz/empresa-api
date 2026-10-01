<?php

namespace App\Http\Controllers\Helpers\combo;

use Illuminate\Support\Facades\Schema;

/**
 * Guarda de esquema de los combos calculados (misión combos-calculados, 30/9/2026).
 *
 * 🔴 POR QUÉ EXISTE. Las tres migraciones de la misión (`2026_09_30_1300*`) agregan tres columnas a
 * `combos` y la tabla `combo_price_type`. Un deploy de empresa sube los archivos ANTES de migrar
 * (`DeploymentService::execute_steps()` de admin-api: `upload_api` -> `sync_env_keys` ->
 * `run_migrations`), así que entre las dos cosas hay una ventana en la que el cliente tiene este
 * código y NO tiene el esquema. En esa ventana, todo camino que nombre una de esas columnas o esa
 * tabla es un `SQLSTATE[42S22]` / `[42S02]`.
 *
 * Y lo que se cae no es "la parte nueva": los caminos nuevos están enganchados en `setFinalPrice`
 * (cada guardado de un artículo), en el cierre de las importaciones y de los recálculos masivos, y
 * en `Combo::scopeWithAll()` (cada listado de combos y cada venta que los carga). Sin esta guarda,
 * en la ventana no se puede guardar un artículo ni abrir una venta con combos. Con ella, TODO el
 * camino nuevo es un no-op y el sistema se comporta como el de antes de la misión.
 *
 * `disponible()` exige LAS DOS cosas —la columna `combos.calcular_desde_articulos` y la tabla
 * `combo_price_type`— y no cada una por separado, a propósito: una base a medio migrar (la primera
 * migración corrió y la segunda no) se trata entera como "no disponible". Un combo calculado sin
 * dónde guardar sus precios por lista es peor que uno que no se calcula.
 *
 * MEMOIZADA, pero con cuidado. `Schema::hasColumn()` / `hasTable()` son consultas a
 * `information_schema`, y esto se pregunta en cada guardado de artículo. Un `true` se guarda para
 * siempre en el proceso (las columnas no desaparecen). Un `false` es otra historia:
 *
 *  - En PHP-FPM (web) los statics mueren con el request, así que memoizar el `false` es inocuo.
 *  - En consola (`queue:work`, el scheduler, los comandos) el proceso vive horas. Un worker que
 *    arrancó DENTRO de la ventana del deploy cachearía `false` y seguiría sin recalcular los combos
 *    hasta reiniciarse, sin ningún error. Por eso en consola el `false` se vuelve a preguntar cada
 *    `SEGUNDOS_DEL_FALSE` segundos: no se paga una consulta por cada artículo de un lote, y a los
 *    pocos segundos de migrar el worker ya ve el esquema.
 *
 * `olvidar()` borra todo, para los tests: sin eso, el memo de un test se le filtraría a los que
 * corren después en el mismo proceso de PHPUnit (que además es "consola").
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 */
class ComboCalculadoEsquemaHelper {

    /** Columna cuya presencia marca que las migraciones de `combos` corrieron. */
    const COLUMNA = 'calcular_desde_articulos';

    /** Tabla de los precios por lista de los combos calculados. */
    const TABLA_DE_PRECIOS = 'combo_price_type';

    /** Cada cuántos segundos, en consola, se vuelve a preguntar si la respuesta memoizada fue `false`. */
    const SEGUNDOS_DEL_FALSE = 30;

    /**
     * `true` una vez confirmado; `null` = todavía no se preguntó (o el último `false` ya venció).
     *
     * @var bool|null
     */
    private static $disponible = null;

    /**
     * Momento (time()) de la última vez que se contestó `false`, para vencerlo en consola.
     *
     * @var int|null
     */
    private static $falso_desde = null;

    /**
     * ¿La base de este cliente ya tiene el esquema de los combos calculados?
     *
     * @return bool
     */
    static function disponible() {

        if (self::$disponible === true) {
            return true;
        }

        if (self::$disponible === false) {

            /* En web el false vale por todo el request. En consola vence y se vuelve a preguntar. */
            $vencido = app()->runningInConsole()
                && !is_null(self::$falso_desde)
                && (time() - self::$falso_desde) >= self::SEGUNDOS_DEL_FALSE;

            if (!$vencido) {
                return false;
            }
        }

        self::$disponible = Schema::hasColumn('combos', self::COLUMNA)
                            && Schema::hasTable(self::TABLA_DE_PRECIOS);

        self::$falso_desde = self::$disponible ? null : time();

        return self::$disponible;
    }

    /**
     * Borra la memoización.
     *
     * La usa el test de la guarda, que necesita que la respuesta se vuelva a preguntar después de
     * esconder y devolver la columna.
     *
     * @return void
     */
    static function olvidar() {
        self::$disponible  = null;
        self::$falso_desde = null;
    }
}
