<?php

namespace App\Http\Controllers\Helpers\sale;

use Illuminate\Support\Facades\Schema;

/**
 * Guarda de esquema de `iva_en_articulos_sin_iva` (mision iva-a-articulos-sin-iva-en-vender,
 * 1/10/2026).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  LA COLUMNA, EN UNA LINEA
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  Vive en `sales` y en `budgets` (migracion `2026_10_01_140000`). Es el check de Vender "Sumar IVA
 *  a los articulos sin IVA": 1 = a los articulos con `aplicar_iva` apagado se les sumo el IVA de su
 *  alicuota en el precio del renglon; 0 = quedaron igual al listado. La API la PERSISTE y la
 *  PROPAGA (confirmar, duplicar, consolidar), pero NO calcula precios con ella: los calcula la SPA,
 *  que la lee de vuelta para saber de que estado parten los precios guardados.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  🔴 POR QUE EXISTE
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  Mismo motivo que `ForzarTotalEsquemaHelper`: un deploy de empresa sube los archivos ANTES de
 *  migrar (`DeploymentService::execute_steps()` de admin-api: `upload_api` -> `sync_env_keys` ->
 *  `run_migrations`). `Sale` y `Budget` declaran `$guarded = []`, asi que Eloquent mete la clave en
 *  el INSERT aunque valga 0, y en el UPDATE la marca sucia aunque se le asigne lo mismo que tenia
 *  (en el original no existe). Sin esta guarda, en esa ventana se cae el alta y la edicion de TODA
 *  venta y de TODO presupuesto con `SQLSTATE[42S22]: Unknown column`.
 *
 *  Si la columna no esta, la clave se OMITE y el comprobante se guarda como hasta hoy (equivale a
 *  0, que es justo lo que la SPA vieja manda). Nunca una excepcion.
 *
 *  Los SIETE puntos de escritura (los mismos de `forzar_total_monto`), medidos el 1/10/2026 con
 *  `grep -rnE "'iva_en_articulos_sin_iva'\s*=>|->iva_en_articulos_sin_iva\s*=" app/`:
 *
 *    1. `SaleController::store()`                      — alta de venta
 *    2. `SaleController::update()`                     — actualizacion de venta
 *    3. `BudgetController::store()`                    — alta de presupuesto
 *    4. `BudgetController::update()`                   — actualizacion de presupuesto
 *    5. `BudgetHelper::saveSale()`                     — confirmar un presupuesto
 *    6. `BudgetDuplicarHelper::duplicate()`            — duplicar un presupuesto
 *    7. `ConsolidarFacturacionHelper::consolidar()`    — la venta consolidada
 *
 *  Si alguien agrega un camino nuevo que crea o copia ventas o presupuestos, ese grep es el barrido
 *  que hay que rehacer. Un camino que no escribe la clave guarda el default 0 (comportamiento de
 *  siempre), que es el modo de falla seguro.
 *
 * ⚠️ MEMOIZADA, Y POR TABLA, con el mismo razonamiento que `ForzarTotalEsquemaHelper`: una consulta
 * por proceso y por tabla, y el `queue:work` booteado dentro de la ventana lo cubre
 * `DeploymentService::step_restart_queue_workers()`, que corre despues de `run_migrations`.
 */
class IvaEnArticulosSinIvaEsquemaHelper {

    /** La columna que puede no estar todavia. */
    const COLUMNA = 'iva_en_articulos_sin_iva';

    /** Las unicas tablas que tienen (o van a tener) la columna. */
    const TABLAS = ['sales', 'budgets'];

    /**
     * Memo `tabla => bool`. Una tabla ausente del mapa = todavia no se pregunto en este proceso.
     *
     * @var array
     */
    private static $existe_por_tabla = [];

    /**
     * ¿La tabla pedida ya tiene la columna?
     *
     * Corta con una excepcion si la tabla no es `sales` ni `budgets`: un `false` silencioso para un
     * nombre mal escrito haria que ese camino nunca guarde el flag sin que nada lo denuncie. Es un
     * error de programacion, revienta en el primer test que lo toque.
     *
     * @param  string  $tabla  'sales' o 'budgets'.
     * @return bool
     *
     * @throws \InvalidArgumentException
     */
    static function hay_columna($tabla) {

        if (!in_array($tabla, Self::TABLAS, true)) {
            throw new \InvalidArgumentException(
                'IvaEnArticulosSinIvaEsquemaHelper: tabla desconocida "'.$tabla.'". Las validas son: '.implode(', ', Self::TABLAS).'.'
            );
        }

        if (!array_key_exists($tabla, Self::$existe_por_tabla)) {
            Self::$existe_por_tabla[$tabla] = Schema::hasColumn($tabla, Self::COLUMNA);
        }

        return Self::$existe_por_tabla[$tabla];
    }

    /**
     * Borra la memoizacion de las dos tablas (para tests que esconden y devuelven la columna).
     *
     * @return void
     */
    static function olvidar() {
        Self::$existe_por_tabla = [];
    }

    /**
     * Saca `iva_en_articulos_sin_iva` de un payload de `create()` si la tabla todavia no tiene la
     * columna. Si la tiene, devuelve el payload tal cual.
     *
     * La clave se escribe en el array literal, al lado de `iva_aplicado` (asi se lee junto con su
     * pariente), y esta funcion envuelve el payload entero.
     *
     * @param  array   $payload  El array que va a `Sale::create()` / `Budget::create()`.
     * @param  string  $tabla    'sales' o 'budgets'.
     * @return array
     */
    static function quitar_si_no_hay_columna($payload, $tabla) {

        if (!Self::hay_columna($tabla)) {
            unset($payload[Self::COLUMNA]);
        }

        return $payload;
    }

    /**
     * Asigna el flag en una ACTUALIZACION, con la regla de `iva_aplicado`: si el request lo trae se
     * toma, y si no lo trae (SPA vieja) se preserva lo guardado. Si la columna no esta, no toca el
     * modelo (asignarlo lo marcaria sucio y el UPDATE nombraria una columna inexistente).
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model          La venta o el presupuesto.
     * @param  mixed                                 $valor_request  Lo que vino en el request (null = no vino).
     * @param  string                                $tabla          'sales' o 'budgets'.
     * @return void
     */
    static function asignar_en_update($model, $valor_request, $tabla) {

        if (!Self::hay_columna($tabla)) {
            return;
        }

        // Si no vino en el request, se queda con lo guardado (la columna nace con default 0).
        if (!is_null($valor_request)) {
            $model->{Self::COLUMNA} = $valor_request;
        }
    }
}
