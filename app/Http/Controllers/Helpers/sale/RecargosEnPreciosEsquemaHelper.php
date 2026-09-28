<?php

namespace App\Http\Controllers\Helpers\sale;

use Illuminate\Support\Facades\Schema;

/**
 * Guarda de esquema de `price_sin_recargos_de_venta` (mision recargos-en-precios-editable,
 * 28/9/2026), y el unico lugar donde se lee y se escribe esa columna.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  LA COLUMNA, EN UNA LINEA
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  Vive en las ocho tablas de renglones (`TABLAS`, abajo). NO NULL = el `price` del renglon tiene
 *  adentro los recargos de venta y el valor es el precio SIN ellos; NULL = no los tiene. La API la
 *  PERSISTE y la PROPAGA en todo camino que escribe o copia renglones, pero NO calcula precios con
 *  ella: los calcula la SPA. Ver la migracion `2026_09_28_100000` para la semantica completa.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  🔴 POR QUE ESTA GUARDA ES MAS GRAVE QUE LA DE `forzar_total_monto`
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  Un deploy de empresa sube los archivos ANTES de migrar (`DeploymentService::execute_steps()` de
 *  admin-api: `upload_api` -> `sync_env_keys` -> `run_migrations`). En esa ventana el cliente tiene
 *  este codigo y no tiene la columna.
 *
 *  `ForzarTotalEsquemaHelper` tapa esa ventana en el ALTA. Esta la tiene que tapar tambien en la
 *  LECTURA: la columna va en el `withPivot()` de `Sale` y `Budget`, y Eloquent la nombra en el
 *  SELECT de CADA carga de renglones. Sin la guarda, en la ventana no se cae solo el alta de toda
 *  venta y todo presupuesto (el `attach()` nombra la columna aunque valga null): se cae ABRIR
 *  cualquier venta, el listado, los PDF, la factura, la cuenta corriente... todo lo que toca
 *  `$sale->articles`. Por eso NINGUN `attach()` ni NINGUN `withPivot()` nombra la columna a mano:
 *  todos pasan por `agregar_al_pivot()` y `columnas_pivot()`.
 *
 *  Si alguien agrega un camino nuevo que escribe renglones, el barrido que hay que rehacer es:
 *  `grep -rnE "(articles|services|combos|promocion_vinotecas)\(\)->(attach|updateExistingPivot)" app/`
 *  — los caminos que NO pasan por aca escriben NULL, que es el modo de falla seguro (la SPA deja
 *  bloqueado el comprobante con la opcion prendida, nunca adivina un precio).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  MEMOIZADA, Y POR TABLA
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  Un mapa `tabla => bool`, no un solo booleano: la migracion agrega la columna tabla por tabla y
 *  una base a medio migrar puede tenerla en `article_sale` y no en `budget_combo` (que ademas puede
 *  no existir). Un memo compartido responderia por la tabla equivocada — mismo motivo por el que
 *  `ForzarTotalEsquemaHelper` tiene dos memos y `budget_combo` / `order_combo` son dos clases.
 *
 *  Se pregunta perezosamente, solo por la tabla que se va a tocar: un request que lee ventas no
 *  paga las consultas de las cuatro tablas de presupuesto. `Schema::hasColumn()` es una consulta a
 *  `information_schema` por tabla; con el memo es una por proceso y por tabla, no una por relacion
 *  construida (y `Sale::articles()` se construye muchas veces por request).
 *
 * ⚠️ Y EL MEMO NO SOBREVIVE A LA MIGRACION, PERO NO PORQUE SI. En PHP-FPM los statics mueren con el
 * request. El caso que si podria quedar clavado es un `queue:work` booteado DENTRO de la ventana,
 * que cachearia `false` y seguiria guardando renglones sin base despues de migrar (eso solo bloquea
 * comprobantes, no rompe precios). Lo cubre `DeploymentService::step_restart_queue_workers()`, que
 * corre DESPUES de `run_migrations`. Mismo razonamiento que `ForzarTotalEsquemaHelper`.
 */
class RecargosEnPreciosEsquemaHelper {

    /** La columna que puede no estar todavia. */
    const COLUMNA = 'price_sin_recargos_de_venta';

    /**
     * La clave del request donde la SPA manda la base de cada renglon. Distinta de la columna a
     * proposito: la respuesta expone `pivot.price_sin_recargos_de_venta`, y el form generico del
     * modulo Presupuestos re-manda el pivot tal cual lo recibio. Si las dos claves se llamaran
     * igual, ese form "mandaria la base" sin saber nada de recargos y la regla de preservacion de
     * `BudgetHelper` (clave ausente + precio igual = se preserva) no tendria como distinguirlo.
     */
    const CLAVE_REQUEST = 'price_vender_sin_recargos';

    /**
     * Las unicas tablas que tienen (o van a tener) la columna. Mismo orden que la migracion.
     *
     * @var array<int,string>
     */
    const TABLAS = [
        'article_sale',
        'sale_service',
        'combo_sale',
        'promocion_vinoteca_sale',
        'article_budget',
        'budget_service',
        'budget_combo',
        'budget_promocion_vinoteca',
    ];

    /**
     * Memo `tabla => bool`. Una tabla ausente del mapa = todavia no se pregunto en este proceso.
     *
     * @var array<string,bool>
     */
    private static $existe_por_tabla = [];

    /**
     * ¿La tabla pedida ya tiene la columna?
     *
     * 🔴 CORTA CON UNA EXCEPCION SI LA TABLA NO ES UNA DE LAS OCHO, y no responde `false` "por las
     * dudas". Un `false` silencioso para un nombre mal escrito ('sales_service', 'article_sales')
     * haria que ese camino guarde la base en NULL para siempre sin que nada lo denuncie: los
     * comprobantes con la opcion prendida quedarian bloqueados y nadie sabria por que. Es un error
     * de programacion, no un estado posible de la base: revienta en el primer test que lo toque.
     *
     * @param  string  $tabla
     * @return bool
     *
     * @throws \InvalidArgumentException
     */
    static function hay_columna($tabla) {

        if (!in_array($tabla, Self::TABLAS, true)) {
            throw new \InvalidArgumentException(
                'RecargosEnPreciosEsquemaHelper: tabla desconocida "'.$tabla.'". Las validas son: '.implode(', ', Self::TABLAS).'.'
            );
        }

        if (!array_key_exists($tabla, Self::$existe_por_tabla)) {
            Self::$existe_por_tabla[$tabla] = Schema::hasColumn($tabla, Self::COLUMNA);
        }

        return Self::$existe_por_tabla[$tabla];
    }

    /**
     * Borra la memoizacion de todas las tablas.
     *
     * La usa el test de la guarda, que necesita que la respuesta se vuelva a preguntar despues de
     * esconder y devolver la columna. Sin esto, el memo de un test se le filtraria a todos los que
     * corren despues en el mismo proceso de PHPUnit.
     *
     * @return void
     */
    static function olvidar() {
        Self::$existe_por_tabla = [];
    }

    /**
     * Las columnas de un `withPivot()`, con la base agregada SOLO si la tabla ya la tiene.
     *
     * Es lo que usan las ocho relaciones de `Sale` y `Budget`. Ver el encabezado: sin esto, la
     * ventana del deploy tumba la LECTURA de toda venta, no solo el alta.
     *
     * @param  array<int,string>  $columnas  Las columnas de siempre del pivot.
     * @param  string             $tabla     Una de `TABLAS`.
     * @return array<int,string>
     */
    static function columnas_pivot($columnas, $tabla) {

        if (Self::hay_columna($tabla)) {
            $columnas[] = Self::COLUMNA;
        }

        return $columnas;
    }

    /**
     * Mete la base en el array de un `attach()` / `updateExistingPivot()` SOLO si la columna existe.
     *
     * El valor se normaliza aca (ver `normalizar()`), asi ningun llamador puede escribir un '' o un
     * texto en la columna.
     *
     * @param  array   $pivot  Las columnas que ya lleva el renglon.
     * @param  mixed   $base   Precio sin recargos, o null.
     * @param  string  $tabla  Una de `TABLAS`.
     * @return array
     */
    static function agregar_al_pivot($pivot, $base, $tabla) {

        if (Self::hay_columna($tabla)) {
            $pivot[Self::COLUMNA] = Self::normalizar($base);
        }

        return $pivot;
    }

    /**
     * La base que manda la SPA en un renglon del request (`price_vender_sin_recargos`), normalizada.
     *
     * Clave ausente y clave en null dan lo mismo ACA (null): la diferencia entre las dos solo le
     * importa a la regla de preservacion de los presupuestos, que pregunta `tiene_clave()` antes.
     *
     * @param  array|mixed  $item
     * @return float|null
     */
    static function base_del_item($item) {

        if (!Self::tiene_clave($item)) {
            return null;
        }

        return Self::normalizar($item[Self::CLAVE_REQUEST]);
    }

    /**
     * ¿El renglon del request trae la clave `price_vender_sin_recargos`, aunque sea en null?
     *
     * `array_key_exists` y no `isset`: un null explicito es "la SPA nueva dice que este precio NO
     * tiene recargo adentro", y eso no es lo mismo que "el que manda no sabe nada de recargos".
     *
     * @param  array|mixed  $item
     * @return bool
     */
    static function tiene_clave($item) {

        return is_array($item) && array_key_exists(Self::CLAVE_REQUEST, $item);
    }

    /**
     * La base guardada en un pivot de Eloquent, o null si no la tiene (o si la columna no existe
     * todavia: en ese caso `withPivot()` no la pidio y el atributo simplemente no esta).
     *
     * @param  \Illuminate\Database\Eloquent\Relations\Pivot|object|null  $pivot
     * @return float|null
     */
    static function base_del_pivot($pivot) {

        if (is_null($pivot) || !isset($pivot->{Self::COLUMNA})) {
            return null;
        }

        return Self::normalizar($pivot->{Self::COLUMNA});
    }

    /**
     * Normaliza una base: null, '' o algo que no es un numero -> null; si no, float redondeado a
     * los seis decimales de la columna.
     *
     * Redondear aca y no dejarselo a MySQL es para que lo que se guarda no dependa del modo SQL del
     * servidor ni de como PHP convierte el float a texto al bindearlo.
     *
     * ⚠️ El 0 es una base VALIDA (un renglon de precio 0 con la opcion prendida sigue siendo 0 sin
     * recargo) y no se confunde con null: por eso `is_numeric` y no un truthy.
     *
     * @param  mixed  $valor
     * @return float|null
     */
    static function normalizar($valor) {

        if (is_null($valor) || $valor === '' || !is_numeric($valor)) {
            return null;
        }

        return round((float) $valor, 6);
    }
}
