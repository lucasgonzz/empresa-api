<?php

namespace App\Http\Controllers\Helpers\sale;

use Illuminate\Support\Facades\Schema;

/**
 * Guarda de esquema de los descuentos adentro de los precios (mision
 * descuentos-aplicar-al-precio-del-articulo, 8/10/2026). Hermana de `RecargosEnPreciosEsquemaHelper`
 * y con el MISMO contrato; es el unico lugar donde se leen y se escriben estas dos columnas:
 *
 *  - `price_sin_descuentos_de_venta` en las ocho tablas de renglones (`TABLAS`): el precio del
 *    renglon SIN los descuentos ni los recargos de venta. NO NULL = el `price` los tiene adentro
 *    (los descuentos, al menos); NULL = no. Ver la migracion `2026_10_08_100000`.
 *  - `aplicar_descuentos_directo_a_items` en `sales` y `budgets` (`TABLAS_FLAG`): la opcion de
 *    VENDER que decide si los descuentos de la venta van adentro del precio de cada renglon o
 *    restados al pie. Ver la migracion `2026_10_08_100100`.
 *
 * La API las PERSISTE y las PROPAGA en todo camino que escribe o copia renglones y comprobantes,
 * pero NO calcula precios con ellas: los calcula la SPA.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  🔴 POR QUE ES UN HERMANO Y NO UNA EXTENSION DE `RecargosEnPreciosEsquemaHelper`
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  Las firmas y el comportamiento de `RecargosEnPreciosEsquemaHelper` no se tocan: hay una suite
 *  entera (`tests/Feature/RecargosEnPrecios`) que depende de ellos, y su test de la guarda esconde
 *  y devuelve SOLO la columna de recargos. Con dos helpers, cada columna tiene su propio memo y
 *  cada test puede esconder la suya sin arrastrar a la otra.
 *
 *  El costo es que un `attach()` escribe las dos bases y entonces anida las dos guardas:
 *
 *      DescuentosEnPreciosEsquemaHelper::agregar_al_pivot(
 *          RecargosEnPreciosEsquemaHelper::agregar_al_pivot([...], <base de recargos>, $tabla),
 *          <base de descuentos>, $tabla
 *      )
 *
 *  y un `withPivot()` pide las dos con `columnas_pivot()` anidado. Es feo a proposito: cada
 *  columna se nombra SOLO por su guarda, y no hay forma de escribir una sin la otra por error.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  🔴 POR QUE ESTA GUARDA EXISTE: EL DEPLOY SUBE EL CODIGO ANTES DE MIGRAR
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  Un deploy de empresa sube los archivos ANTES de migrar (`DeploymentService::execute_steps()` de
 *  admin-api: `upload_api` -> `sync_env_keys` -> `run_migrations`). En esa ventana el cliente
 *  tiene este codigo y no tiene las columnas, y nombrarlas tumba cosas distintas:
 *
 *   - La de los renglones va en el `withPivot()` de `Sale` y `Budget`: Eloquent la nombra en el
 *     SELECT de CADA carga de renglones, asi que sin guarda no se cae solo el alta sino ABRIR
 *     cualquier venta, el listado, los PDF, la factura y la cuenta corriente.
 *   - La del flag viaja en el INSERT de `Sale::create()` / `Budget::create()` (los dos declaran
 *     `$guarded = []`, y Eloquent manda la clave aunque valga 0) y en el UPDATE de cualquier
 *     edicion (asignarla la marca sucia aunque tenga el mismo valor): sin guarda se cae el alta
 *     y la edicion de TODA venta y TODO presupuesto con `Unknown column`.
 *
 *  Por eso NINGUN `attach()`, NINGUN `updateExistingPivot()` y NINGUN `withPivot()` nombra la
 *  columna de los renglones a mano, y NINGUN `create()` / asignacion nombra el flag a mano.
 *
 *  Si alguien agrega un camino nuevo que escribe renglones, el barrido que hay que rehacer es:
 *  `git grep -nE "(articles|services|combos|promocion_vinotecas)\(\)->(attach|updateExistingPivot)" -- app`.
 *  Los caminos que NO pasan por aca escriben NULL, que es el modo de falla seguro: el renglon queda
 *  sin descuento adentro y la SPA, si la venta tiene la opcion prendida, parte del `price` como
 *  base (nunca adivina un precio). Para el flag: `git grep -n "aplicar_recargos_directo_a_items" -- app`
 *  lista los puntos donde el flag hermano se escribe y se copia, que son los mismos.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  MEMOIZADA, POR TABLA Y POR COLUMNA
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  Dos mapas `tabla => bool`, uno por columna: la migracion agrega las columnas tabla por tabla y
 *  una base a medio migrar puede tenerla en `article_sale` y no en `budget_combo` (que ademas
 *  puede no existir), o tener la de los renglones y no la del flag. Un memo compartido
 *  responderia por la tabla o la columna equivocada. Se pregunta perezosamente, solo por la tabla
 *  que se va a tocar: `Schema::hasColumn()` es una consulta a `information_schema`, y con el memo
 *  es una por proceso, tabla y columna.
 *
 * ⚠️ Y EL MEMO NO SOBREVIVE A LA MIGRACION, PERO NO PORQUE SI. En PHP-FPM los statics mueren con
 * el request. El caso que si podria quedar clavado es un `queue:work` booteado DENTRO de la
 * ventana, que cachearia `false` y seguiria guardando sin las columnas despues de migrar (eso solo
 * deja el comprobante sin la opcion o sin la base, no rompe precios). Lo cubre
 * `DeploymentService::step_restart_queue_workers()`, que corre DESPUES de `run_migrations`. Mismo
 * razonamiento que `RecargosEnPreciosEsquemaHelper`.
 */
class DescuentosEnPreciosEsquemaHelper {

    /** La columna de los renglones que puede no estar todavia. */
    const COLUMNA = 'price_sin_descuentos_de_venta';

    /**
     * La clave del request donde la SPA manda la base de cada renglon. Distinta de la columna a
     * proposito, por el mismo motivo que `RecargosEnPreciosEsquemaHelper::CLAVE_REQUEST`: el form
     * generico del modulo Presupuestos re-manda el pivot tal cual lo recibio (con
     * `price_sin_descuentos_de_venta`), y si las dos claves se llamaran igual ese form "mandaria la
     * base" sin saber nada de descuentos y la regla de preservacion de `BudgetHelper` (clave
     * ausente + precio igual = se preserva) no tendria como distinguirlo.
     */
    const CLAVE_REQUEST = 'price_vender_sin_descuentos';

    /** La columna booleana de `sales` y `budgets` que puede no estar todavia. */
    const COLUMNA_FLAG = 'aplicar_descuentos_directo_a_items';

    /**
     * Las ocho tablas de renglones que tienen (o van a tener) `COLUMNA`. Son las mismas de la
     * columna de recargos, en el mismo orden que las dos migraciones.
     *
     * @var array<int,string>
     */
    const TABLAS = RecargosEnPreciosEsquemaHelper::TABLAS;

    /**
     * Las dos tablas que tienen (o van a tener) `COLUMNA_FLAG`.
     *
     * @var array<int,string>
     */
    const TABLAS_FLAG = ['sales', 'budgets'];

    /**
     * Memo `tabla => bool` de `COLUMNA`. Una tabla ausente = todavia no se pregunto en este proceso.
     *
     * @var array<string,bool>
     */
    private static $existe_por_tabla = [];

    /**
     * Memo `tabla => bool` de `COLUMNA_FLAG`. Va aparte del de arriba: son otras tablas y otra
     * columna, y una base a medio migrar puede tener una y no la otra.
     *
     * @var array<string,bool>
     */
    private static $existe_flag_por_tabla = [];

    /**
     * ¿La tabla de renglones pedida ya tiene `price_sin_descuentos_de_venta`?
     *
     * 🔴 CORTA CON UNA EXCEPCION SI LA TABLA NO ES UNA DE LAS OCHO, y no responde `false` "por las
     * dudas": un `false` silencioso para un nombre mal escrito haria que ese camino guarde la base
     * en NULL para siempre sin que nada lo denuncie. Es un error de programacion, no un estado
     * posible de la base: revienta en el primer test que lo toque.
     *
     * @param  string  $tabla  Una de `TABLAS`.
     * @return bool
     *
     * @throws \InvalidArgumentException
     */
    static function hay_columna($tabla) {

        if (!in_array($tabla, Self::TABLAS, true)) {
            throw new \InvalidArgumentException(
                'DescuentosEnPreciosEsquemaHelper: tabla de renglones desconocida "'.$tabla.'". Las validas son: '.implode(', ', Self::TABLAS).'.'
            );
        }

        if (!array_key_exists($tabla, Self::$existe_por_tabla)) {
            Self::$existe_por_tabla[$tabla] = Schema::hasColumn($tabla, Self::COLUMNA);
        }

        return Self::$existe_por_tabla[$tabla];
    }

    /**
     * ¿La tabla pedida ya tiene `aplicar_descuentos_directo_a_items`?
     *
     * Corta con una excepcion si la tabla no es `sales` ni `budgets`, por el mismo motivo que
     * `hay_columna()`: un `false` silencioso nunca guardaria la opcion y nadie se enteraria.
     *
     * @param  string  $tabla  'sales' o 'budgets'.
     * @return bool
     *
     * @throws \InvalidArgumentException
     */
    static function hay_columna_flag($tabla) {

        if (!in_array($tabla, Self::TABLAS_FLAG, true)) {
            throw new \InvalidArgumentException(
                'DescuentosEnPreciosEsquemaHelper: tabla del flag desconocida "'.$tabla.'". Las validas son: '.implode(', ', Self::TABLAS_FLAG).'.'
            );
        }

        if (!array_key_exists($tabla, Self::$existe_flag_por_tabla)) {
            Self::$existe_flag_por_tabla[$tabla] = Schema::hasColumn($tabla, Self::COLUMNA_FLAG);
        }

        return Self::$existe_flag_por_tabla[$tabla];
    }

    /**
     * Borra la memoizacion de todas las tablas y las dos columnas.
     *
     * La usa el test de la guarda, que necesita que la respuesta se vuelva a preguntar despues de
     * esconder y devolver las columnas. Sin esto, el memo de un test se le filtraria a todos los
     * que corren despues en el mismo proceso de PHPUnit.
     *
     * @return void
     */
    static function olvidar() {
        Self::$existe_por_tabla      = [];
        Self::$existe_flag_por_tabla = [];
    }

    /**
     * Las columnas de un `withPivot()`, con `price_sin_descuentos_de_venta` agregada SOLO si la
     * tabla ya la tiene. Se anida con la de recargos:
     * `DescuentosEnPreciosEsquemaHelper::columnas_pivot(RecargosEnPreciosEsquemaHelper::columnas_pivot([...], $t), $t)`.
     *
     * @param  array<int,string>  $columnas  Las columnas de siempre del pivot (y las de recargos).
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
     * Mete la base de descuentos en el array de un `attach()` / `updateExistingPivot()` SOLO si la
     * columna existe. El valor se normaliza aca (ver `normalizar()`), asi ningun llamador puede
     * escribir un '' o un texto en la columna.
     *
     * @param  array   $pivot  Las columnas que ya lleva el renglon (con la base de recargos, si la hay).
     * @param  mixed   $base   Precio original (sin descuentos ni recargos), o null.
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
     * La base de descuentos que manda la SPA en un renglon del request
     * (`price_vender_sin_descuentos`), normalizada.
     *
     * Clave ausente y clave en null dan lo mismo ACA (null): la diferencia solo le importa a la
     * regla de preservacion de los presupuestos, que pregunta `tiene_clave()` antes.
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
     * ¿El renglon del request trae la clave `price_vender_sin_descuentos`, aunque sea en null?
     *
     * `array_key_exists` y no `isset`: un null explicito es "la SPA nueva dice que este precio NO
     * tiene descuento adentro", y eso no es lo mismo que "el que manda no sabe nada de
     * descuentos" (una SPA anterior a esta mision).
     *
     * @param  array|mixed  $item
     * @return bool
     */
    static function tiene_clave($item) {

        return is_array($item) && array_key_exists(Self::CLAVE_REQUEST, $item);
    }

    /**
     * La base de descuentos guardada en un pivot de Eloquent, o null si no la tiene (o si la
     * columna no existe todavia: en ese caso `withPivot()` no la pidio y el atributo no esta).
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
     * los seis decimales de la columna. Es EXACTAMENTE la normalizacion de la columna hermana
     * (incluido que el 0 es una base valida y no se confunde con null), asi que se delega.
     *
     * @param  mixed  $valor
     * @return float|null
     */
    static function normalizar($valor) {

        return RecargosEnPreciosEsquemaHelper::normalizar($valor);
    }

    /**
     * Saca `aplicar_descuentos_directo_a_items` de un payload de `create()` si la tabla todavia no
     * tiene la columna. Si la tiene, devuelve el payload tal cual.
     *
     * La clave se escribe en el array literal, al lado de `aplicar_recargos_directo_a_items` (asi se
     * lee junto con su hermana), y esta funcion envuelve el payload entero: las guardas de las
     * otras columnas nuevas (forzar total, IVA en articulos sin IVA, cobro del presupuesto) se
     * anidan unas dentro de otras y cada una saca SU clave.
     *
     * @param  array   $payload  El array que va a `Sale::create()` / `Budget::create()`.
     * @param  string  $tabla    'sales' o 'budgets'.
     * @return array
     */
    static function quitar_flag_si_no_hay_columna($payload, $tabla) {

        if (!Self::hay_columna_flag($tabla)) {
            unset($payload[Self::COLUMNA_FLAG]);
        }

        return $payload;
    }

    /**
     * Asigna el flag en una ACTUALIZACION con la regla de su hermana `aplicar_recargos_directo_a_items`:
     * si el request lo trae se toma, y si no lo trae (SPA anterior a esta mision) se PRESERVA lo
     * guardado. Una SPA que no manda la clave no puede cambiar el comportamiento del comprobante:
     * asignarla pelada la dejaria en null con los precios del pivot todavia descontados, y el
     * descuento se restaria DOS veces.
     *
     * Si la columna no esta, no toca el modelo (asignarlo lo marcaria sucio y el UPDATE nombraria
     * una columna inexistente).
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model          La venta o el presupuesto.
     * @param  mixed                                 $valor_request  Lo que vino en el request (null = no vino).
     * @param  string                                $tabla          'sales' o 'budgets'.
     * @return void
     */
    static function asignar_flag_en_update($model, $valor_request, $tabla) {

        if (!Self::hay_columna_flag($tabla)) {
            return;
        }

        if (!is_null($valor_request)) {
            $model->{Self::COLUMNA_FLAG} = $valor_request;
        }
    }

    /**
     * ¿Hay que frenar esta edicion de una VENTA porque la manda una SPA anterior a la opcion?
     *
     * ─────────────────────────────────────────────────────────────────────────────
     *  🔴 EL CASO QUE ESTO CIERRA: LA PESTAÑA VIEJA DE OTRA CAJA
     * ─────────────────────────────────────────────────────────────────────────────
     *
     *  Una venta guardada con `aplicar_descuentos_directo_a_items` = 1 tiene los renglones con el
     *  descuento ADENTRO del precio. La SPA anterior a la opcion (una PWA cacheada, o la pestaña que
     *  quedo abierta en OTRA caja del mismo comercio) no conoce el flag: abre esa venta, muestra los
     *  precios tal cual y su pie vuelve a RESTAR el descuento, asi que su PUT manda un `total`
     *  descontado dos veces. `SaleController::update()` guarda ese `total` tal cual (no lo valida
     *  contra los renglones) y `asignar_flag_en_update()` preserva el flag en 1, de modo que la venta
     *  quedaria PARA SIEMPRE con los renglones descontados una vez y el total descontado dos: el
     *  cliente paga de menos y la cuenta corriente, la caja y la factura heredan el error.
     *
     *  Preservar el flag evita que `getTotalSale()` reste de nuevo, pero no puede arreglar un `total`
     *  que la SPA vieja ya calculo mal. La unica salida segura es no dejar que esa SPA edite esa
     *  venta: se le contesta 409 y se le pide al vendedor que recargue la pagina.
     *
     *  Solo ventas. Los presupuestos quedan afuera por decision de la mision: `BudgetController::store()`
     *  valida el total contra `BudgetHelper::getTotal()` (margen de 3) y rebota a la SPA vieja solo.
     *  (El `update()` de presupuestos no valida el total; si hiciera falta cerrarlo, seria otro candado.)
     *
     *  ¿Como se distingue la SPA nueva de la vieja? La nueva manda `aplicar_descuentos_directo_a_items`
     *  SIEMPRE en el PUT de VENDER, incluido el 0 (`vender.js` / `previus_sales.js`), y `update()` solo
     *  se alcanza con `items` de VENDER, asi que una edicion legitima sin la clave no existe. Por eso
     *  el criterio es la AUSENCIA de la clave (`exists()`, que ve tambien un null explicito) y no su
     *  valor: un 0 explicito es una SPA nueva que apago la opcion, y pasa.
     *
     *  Si la columna no existe todavia (ventana del deploy) no hay nada que proteger: ninguna venta
     *  puede tener el flag en 1.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $sale     La venta tal como esta guardada, antes de tocarla.
     * @param  \Illuminate\Http\Request             $request  El PUT de la edicion.
     * @return bool  true = cortar con 409.
     */
    static function requiere_spa_nueva($sale, $request) {

        if (!Self::hay_columna_flag('sales')) {
            return false;
        }

        if (!$sale->{Self::COLUMNA_FLAG}) {
            return false;
        }

        return !$request->exists(Self::COLUMNA_FLAG);
    }

    /**
     * El mensaje que ve el vendedor cuando `requiere_spa_nueva()` frena su edicion. Habla en
     * criollo: no nombra ninguna columna ni ningun flag.
     *
     * @return string
     */
    static function mensaje_spa_nueva() {

        return 'Esta venta tiene los descuentos aplicados directamente en los precios de los articulos, '.
               'y esta pantalla es de una version anterior del sistema que no sabe editarla: si la guardara, '.
               'volveria a restar el descuento y el total quedaria mal. Recarga la pagina (o actualiza el '.
               'sistema) y volve a abrir la venta para editarla.';
    }
}
