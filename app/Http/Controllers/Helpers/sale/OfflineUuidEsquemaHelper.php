<?php

namespace App\Http\Controllers\Helpers\sale;

use Illuminate\Support\Facades\Schema;

/**
 * Guarda de esquema de `sales.offline_uuid` (misión ingreso-sin-internet, 23/10/2026).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  LA COLUMNA, EN UNA LINEA
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  Vive solo en `sales` (migración `2026_10_23_100000`). Es el identificador que la SPA le pone a
 *  una venta hecha SIN CONEXIÓN en el momento de guardarla en IndexedDB, y que viaja en el
 *  `POST /sale` cuando la venta se sube. Con él `SaleController::store()` reconoce una venta que ya
 *  subió (respuesta cortada, reintento) y la devuelve en vez de crearla dos veces. Las ventas
 *  online lo dejan en null. Ver `VentaOfflineHelper`.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  🔴 POR QUE EXISTE
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  Mismo motivo que `ForzarTotalEsquemaHelper` e `IvaEnArticulosSinIvaEsquemaHelper`: un deploy de
 *  empresa sube los archivos ANTES de migrar (`DeploymentService::execute_steps()` de admin-api:
 *  `upload_api` -> `sync_env_keys` -> `run_migrations`). `Sale` declara `$guarded = []`, así que
 *  Eloquent mete `offline_uuid` en el INSERT aunque valga null, y en esa ventana se caería el alta
 *  de TODA venta, online u offline, con `SQLSTATE[42S22]: Unknown column`.
 *
 *  Si la columna no está:
 *   - la clave se OMITE del payload de `Sale::create()` (la venta se guarda como hasta hoy), y
 *   - `VentaOfflineHelper::offline_uuid_utilizable()` devuelve null, así que `store()` vuelve a
 *     la guarda anti doble clic de 5 segundos (`venta_ya_cread()`) igual que antes de esta misión.
 *     No se puede buscar por una columna que no existe, y quedarse sin ninguna de las dos guardas
 *     en la ventana del deploy sería peor que lo de hoy.
 *  Nunca una excepción.
 *
 *  Un solo punto de escritura, medido el 23/10/2026 con
 *  `grep -rnE "'offline_uuid'\s*=>|->offline_uuid\s*=" app/`: `SaleController::store()`. La
 *  columna NO se copia al confirmar, duplicar ni consolidar: identifica UNA subida de UNA venta
 *  offline, y copiarla a otra venta haría que el índice único rechace la copia.
 *
 * ⚠️ MEMOIZADA, con el mismo razonamiento que `ForzarTotalEsquemaHelper`: una consulta por proceso,
 * y el `queue:work` booteado dentro de la ventana lo cubre
 * `DeploymentService::step_restart_queue_workers()`, que corre después de `run_migrations`.
 */
class OfflineUuidEsquemaHelper {

    /** La columna que puede no estar todavía. */
    const COLUMNA = 'offline_uuid';

    /** La única tabla que tiene (o va a tener) la columna. */
    const TABLA = 'sales';

    /**
     * Resultado memoizado de `Schema::hasColumn('sales', 'offline_uuid')`.
     *
     * `null` = todavía no se preguntó en este proceso.
     *
     * @var bool|null
     */
    private static $existe = null;

    /**
     * ¿La tabla `sales` de este cliente ya tiene la columna?
     *
     * @return bool
     */
    static function hay_columna() {

        if (is_null(Self::$existe)) {
            Self::$existe = Schema::hasColumn(Self::TABLA, Self::COLUMNA);
        }

        return Self::$existe;
    }

    /**
     * Borra la memoización (para tests que esconden y devuelven la columna, y para que el memo de
     * un test no se le filtre a los que corren después en el mismo proceso de PHPUnit).
     *
     * @return void
     */
    static function olvidar() {
        Self::$existe = null;
    }

    /**
     * Saca `offline_uuid` de un payload de `Sale::create()` si la tabla todavía no tiene la
     * columna. Si la tiene, devuelve el payload tal cual (con la clave en null para una venta
     * online, que es lo que corresponde).
     *
     * La clave se escribe en el array literal de `store()` y esta función envuelve el payload,
     * igual que `IvaEnArticulosSinIvaEsquemaHelper::quitar_si_no_hay_columna()`.
     *
     * @param  array  $payload  El array que va a `Sale::create()`.
     * @return array
     */
    static function quitar_si_no_hay_columna($payload) {

        if (!Self::hay_columna()) {
            unset($payload[Self::COLUMNA]);
        }

        return $payload;
    }
}
