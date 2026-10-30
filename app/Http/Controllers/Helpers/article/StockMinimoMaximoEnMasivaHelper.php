<?php

namespace App\Http\Controllers\Helpers\article;

use App\Models\Address;
use App\Models\Article;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * EL STOCK MÍNIMO Y MÁXIMO POR SUCURSAL EN UNA ACTUALIZACIÓN MASIVA (misión agente-mejoras-uso-real,
 * E2 punto 1, 29/10/2026).
 *
 * 🔴 EL CASO REAL. Pablo (Ferre Total, CONV 14) pidió «a todos los clavos punta París poneles stock
 * mínimo 10» y el agente le dijo que el stock mínimo no se podía cambiar con la actualización masiva
 * —no estaba en su lista blanca— y le ofreció, en su lugar, setearles el STOCK en 10, que habría
 * generado un ingreso manual de mercadería real. Ahora la masiva del asistente sabe poner el mínimo:
 *
 *  - con 0 o 1 sucursal, en `articles.stock_min`, por la rama genérica `set_` de
 *    MasiveUpdateHelper::apply_form_change() (no pasa por acá);
 *  - con 2 o más, en el pivote `address_article` de CADA sucursal (`stock_min` / `stock_max`), que es
 *    lo que mira el reporte nocturno de Alertas por sucursal (InventoryPerformanceHelper) y lo que
 *    edita la pantalla de stock por sucursal (UpdateAddressesStockHelper::set_stock_min_max()). Eso
 *    es ESTA clase.
 *
 * La clave del update_form es `stock_min_sucursal_{address_id}` o `stock_max_sucursal_{address_id}`
 * (una por sucursal: "todas" se expande al proponer). No es una columna de `articles`, así que tiene
 * su rama propia en apply_form_change() y en revert_article_pivot_changes(), ANTES de las genéricas
 * —el mismo patrón que CatalogoPorListaHelper::es_clave_de_masiva()—: sin ella, la rama genérica
 * haría `$model->stock_min_sucursal_5 = 10; $model->save()` y la masiva entera terminaría en
 * "Unknown column".
 *
 * Reglas al aplicar:
 *  - Solo se escribe la columna que se cambia (el mínimo O el máximo): la otra queda como está. Es la
 *    trampa que documenta PropuestaStockIaHelper::payload_de_stock_en_deposito() para
 *    set_stock_min_max(), que escribe las dos y pisa en null la que no viaja; acá no se pasa por ese
 *    método, se escribe una sola columna del pivote con updateExistingPivot().
 *  - 🔴 Solo sobre una fila de pivote QUE YA EXISTE. Un artículo sin fila en esa sucursal no tiene
 *    stock repartido ahí, y abrirle una fila lo pasaría a "repartir por sucursales"
 *    (MasiveUpdateHelper::aplicar_stock_por_movimiento() decide el modo de stock contando esas
 *    filas): un cambio de mínimo no puede cambiar cómo se lleva el stock de un artículo. Esos se
 *    saltean (la tarjeta ya avisó cuántos son, ver contar_sin_fila()). Salvo el mínimo de uno que no
 *    reparte en NINGUNA sucursal: ése va a `articles.stock_min`, que es lo que le mira el reporte
 *    de Alertas (revisión independiente, 30/10/2026; ver aplicar_minimo_general()).
 *  - La sucursal tiene que ser del dueño de la masiva y estar viva (no borrada): se valida UNA vez por
 *    corrida con la memoria de la corrida (CatalogoPorListaHelper::nueva_memoria_de_corrida()).
 *  - El artículo tiene que ser del dueño: la selección manual de la masiva resuelve por id.
 *  - Devuelve el cambio con la forma de siempre (`prop_key`, `old_value`, `new_value`, `operation`,
 *    `form_key`) con el valor viejo EXACTO (null incluido), para que la reversión deje la fila como
 *    estaba.
 *
 * El permiso NO se mira acá: en la cola no hay sesión. Lo miran la propuesta y la ejecución de la
 * tarjeta (PropuestaActualizacionMasivaIaHelper), con `article.stock_min_max` y
 * PermisoStockPorSucursalHelper sobre la persona que confirma.
 *
 * PHP 7.4: sin match, str_contains, nullsafe, argumentos nombrados ni union types.
 */
class StockMinimoMaximoEnMasivaHelper
{
    /** Prefijo de la clave del mínimo de una sucursal. */
    const PREFIJO_MINIMO = 'stock_min_sucursal_';

    /** Prefijo de la clave del máximo de una sucursal. */
    const PREFIJO_MAXIMO = 'stock_max_sucursal_';

    /** `type` del update_form: no lo lee ninguna rama genérica de apply_form_change(). */
    const TIPO = 'stock_min_max_sucursal';

    /** Forma de las dos claves: el grupo 1 dice cuál columna y el 2, la sucursal. */
    const REGEX_CLAVE = '/^stock_(min|max)_sucursal_(\d+)$/';

    /**
     * La clave del mínimo de una sucursal.
     *
     * @param  int  $address_id
     * @return string
     */
    public static function clave_minimo($address_id)
    {
        return self::PREFIJO_MINIMO . (int) $address_id;
    }

    /**
     * La clave del máximo de una sucursal.
     *
     * @param  int  $address_id
     * @return string
     */
    public static function clave_maximo($address_id)
    {
        return self::PREFIJO_MAXIMO . (int) $address_id;
    }

    /**
     * Si una clave del update_form (o de un historial de masiva, al revertir) es de esta clase.
     *
     * @param  mixed  $clave
     * @return bool
     */
    public static function es_clave_de_masiva($clave)
    {
        return is_string($clave) && preg_match(self::REGEX_CLAVE, $clave) === 1;
    }

    /**
     * La columna del pivote (`stock_min` o `stock_max`) y la sucursal de una clave, o null si la
     * clave no tiene esa forma.
     *
     * @param  mixed  $clave
     * @return array|null  ['columna' => 'stock_min'|'stock_max', 'address_id' => int]
     */
    public static function partes_de_la_clave($clave)
    {
        if (!is_string($clave) || preg_match(self::REGEX_CLAVE, $clave, $m) !== 1) {

            return null;
        }

        return [
            'columna'    => $m[1] === 'min' ? 'stock_min' : 'stock_max',
            'address_id' => (int) $m[2],
        ];
    }

    /**
     * Aplica la clave a UN artículo (rama de MasiveUpdateHelper::apply_form_change()).
     *
     * @param  mixed  $model  El artículo (cualquier otro modelo se ignora).
     * @param  array  $form  ['type', 'key', 'value'].
     * @param  \App\Models\User|null  $owner  Dueño de la masiva, resuelto una vez por corrida.
     * @param  \ArrayObject|null  $memoria  Memoria de ESTA corrida.
     * @return array|null  ['prop_key', 'old_value', 'new_value', 'operation', 'form_key'] o null.
     */
    public static function aplicar_en_masiva($model, array $form, $owner = null, $memoria = null)
    {
        $partes = self::partes_de_la_clave(isset($form['key']) ? $form['key'] : null);

        if (is_null($partes) || !($model instanceof Article)) {

            return null;
        }

        // Un mínimo o un máximo es un entero no negativo (la columna es INT): otra cosa no se escribe.
        $valor = isset($form['value']) ? $form['value'] : null;

        if (!is_numeric($valor) || (float) $valor < 0) {

            return null;
        }

        $nuevo = (int) round((float) $valor);

        // Dueño de la masiva; sin él (llamada fuera de process_update) se toma el del artículo.
        $owner_id = !is_null($owner) ? (int) $owner->id : (int) $model->user_id;

        if ((int) $model->user_id !== $owner_id) {

            self::avisar_una_vez($memoria, 'articulo-ajeno:' . $owner_id, 'StockMinimoMaximoEnMasivaHelper: masiva sobre el articulo ' . $model->id . ' de otro dueño; no se toca su mínimo ni su máximo (ni el de los demás artículos ajenos de esta corrida).');

            return null;
        }

        if (!self::sucursal_es_del_dueno($partes['address_id'], $owner_id, $memoria)) {

            self::avisar_una_vez($memoria, 'sucursal-invalida:' . $owner_id . ':' . $partes['address_id'], 'StockMinimoMaximoEnMasivaHelper: la sucursal ' . $partes['address_id'] . ' no existe o no es del dueño ' . $owner_id . '; se ignora la clave en todos los artículos de esta corrida.');

            return null;
        }

        $fila = self::fila($model->id, $partes['address_id']);

        /*
         * Sin fila en esa sucursal no se abre una (ver el docblock de la clase). PERO si el artículo
         * no reparte el stock en NINGUNA sucursal (revisión independiente, 30/10/2026), su stock es
         * el general y el reporte nocturno de Alertas le mira `articles.stock_min`
         * (InventoryPerformanceHelper): el mínimo va ahí, que es donde sirve. El máximo no tiene
         * columna general: se saltea.
         */
        if (is_null($fila)) {

            if ($partes['columna'] === 'stock_min' && !self::reparte($model->id)) {

                return self::aplicar_minimo_general($model, $nuevo, $form['key']);
            }

            return null;
        }

        $viejo = is_null($fila->{$partes['columna']}) ? null : (int) $fila->{$partes['columna']};

        if (!is_null($viejo) && $viejo === $nuevo) {

            return null;
        }

        $model->addresses()->updateExistingPivot($partes['address_id'], [$partes['columna'] => $nuevo]);

        return [
            'prop_key'  => $form['key'],
            'old_value' => $viejo,
            'new_value' => $nuevo,
            'operation' => 'set',
            'form_key'  => $form['key'],
        ];
    }

    /**
     * La rama espejo para la reversión (MasiveUpdateHelper::revert_article_pivot_changes()): vuelve a
     * escribir en el pivote el valor que tenía antes de la masiva (null incluido).
     *
     * Si la fila ya no existe (se borró la sucursal o el reparto entre la masiva y la reversión) no
     * hay nada que restaurar y no se abre una: se saltea.
     *
     * @param  \App\Models\Article  $model  Ya acotado al dueño de la masiva.
     * @param  string  $prop_key  `stock_min_sucursal_{id}` o `stock_max_sucursal_{id}`.
     * @param  mixed  $viejo  El `old` guardado en el historial.
     * @param  \App\Models\User|null  $owner
     * @param  \ArrayObject|null  $memoria
     * @return array|null  ['old', 'new', 'operation' => 'revert'] o null.
     */
    public static function revertir_en_masiva($model, $prop_key, $viejo, $owner = null, $memoria = null)
    {
        $partes = self::partes_de_la_clave($prop_key);

        if (is_null($partes) || !($model instanceof Article)) {

            return null;
        }

        $owner_id = !is_null($owner) ? (int) $owner->id : (int) $model->user_id;

        if (!self::sucursal_es_del_dueno($partes['address_id'], $owner_id, $memoria)) {

            self::avisar_una_vez($memoria, 'reversion-sucursal-invalida:' . $owner_id . ':' . $partes['address_id'], 'StockMinimoMaximoEnMasivaHelper: no se revierte el mínimo/máximo de la sucursal ' . $partes['address_id'] . ' (primer artículo: ' . $model->id . '): ya no existe o no es del dueño.');

            return null;
        }

        $fila = self::fila($model->id, $partes['address_id']);

        if (is_null($fila)) {

            return null;
        }

        // Un old ilegible del historial no se escribe: mejor no tocar que inventar.
        if (!is_null($viejo) && !is_numeric($viejo)) {

            return null;
        }

        $a_restaurar = is_null($viejo) ? null : (int) $viejo;
        $antes = is_null($fila->{$partes['columna']}) ? null : (int) $fila->{$partes['columna']};

        $model->addresses()->updateExistingPivot($partes['address_id'], [$partes['columna'] => $a_restaurar]);

        return [
            'old'       => $antes,
            'new'       => $a_restaurar,
            'operation' => 'revert',
        ];
    }

    /**
     * Cuántos de los artículos dados reparten el stock en ALGUNA sucursal pero NO tienen fila en ésta:
     * a esos la masiva no les pone el mínimo ahí (ver el docblock de la clase). Los que no reparten en
     * ninguna van aparte (contar_sin_reparto()): a esos se les pone el mínimo general. Lo usa la
     * tarjeta para avisarlo antes de confirmar. Una consulta.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $articulos  La query de los alcanzados.
     * @param  int  $address_id
     * @return int
     */
    public static function contar_sin_fila($articulos, $address_id)
    {
        return (int) (clone $articulos)
            ->whereExists(function ($q) {
                self::filas_de_sucursales($q);
            })
            ->whereNotExists(function ($q) use ($address_id) {
                $q->selectRaw('1')
                    ->from('address_article')
                    ->whereColumn('address_article.article_id', 'articles.id')
                    ->where('address_article.address_id', (int) $address_id);
            })
            ->count();
    }

    /**
     * Cuántos de los artículos dados NO reparten el stock en ninguna sucursal (revisión independiente,
     * 30/10/2026): a esos el mínimo se les pone en `articles.stock_min` (ver aplicar_en_masiva()) y el
     * máximo no se les pone. Una consulta.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $articulos  La query de los alcanzados.
     * @return int
     */
    public static function contar_sin_reparto($articulos)
    {
        return (int) (clone $articulos)
            ->whereNotExists(function ($q) {
                self::filas_de_sucursales($q);
            })
            ->count();
    }

    /**
     * La subconsulta "el artículo tiene alguna fila de stock en una sucursal de verdad" (sin los
     * domicilios de comprador, `buyer_id`), correlacionada con `articles.id`. Es el mismo criterio con
     * el que MasiveUpdateHelper::aplicar_stock_por_movimiento() decide si el artículo reparte.
     *
     * @param  \Illuminate\Database\Query\Builder  $q
     * @return void
     */
    protected static function filas_de_sucursales($q)
    {
        $q->selectRaw('1')
            ->from('address_article')
            ->join('addresses', 'addresses.id', '=', 'address_article.address_id')
            ->whereColumn('address_article.article_id', 'articles.id')
            ->whereNull('addresses.buyer_id');
    }

    /**
     * true si el artículo reparte el stock en alguna sucursal de verdad (ver filas_de_sucursales()).
     *
     * @param  int  $article_id
     * @return bool
     */
    protected static function reparte($article_id)
    {
        return DB::table('address_article')
            ->join('addresses', 'addresses.id', '=', 'address_article.address_id')
            ->where('address_article.article_id', (int) $article_id)
            ->whereNull('addresses.buyer_id')
            ->exists();
    }

    /**
     * El mínimo GENERAL del artículo (`articles.stock_min`) para uno que no reparte el stock (revisión
     * independiente, 30/10/2026). Devuelve el cambio con `prop_key` = `stock_min`, la columna de
     * `articles`, así que la reversión de siempre lo restaura por la rama genérica (el `old` exacto,
     * null incluido). Con "todas", la segunda sucursal encuentra el mismo valor y no registra nada.
     *
     * @param  \App\Models\Article  $model
     * @param  int  $nuevo
     * @param  string  $form_key
     * @return array|null
     */
    protected static function aplicar_minimo_general($model, $nuevo, $form_key)
    {
        // De la fila, no del modelo: una sucursal anterior de la misma masiva pudo haberlo escrito.
        $viejo = DB::table('articles')->where('id', (int) $model->id)->value('stock_min');
        $viejo = is_null($viejo) ? null : (int) $viejo;

        if (!is_null($viejo) && $viejo === (int) $nuevo) {

            return null;
        }

        $model->stock_min = (int) $nuevo;
        $model->save();

        return [
            'prop_key'  => 'stock_min',
            'old_value' => $viejo,
            'new_value' => (int) $nuevo,
            'operation' => 'set',
            'form_key'  => $form_key,
        ];
    }

    /**
     * La fila del pivote (artículo, sucursal), leída de la base y no de la relación (una relación ya
     * cargada podría traer números de antes), o null si no hay.
     *
     * @param  int  $article_id
     * @param  int  $address_id
     * @return object|null
     */
    protected static function fila($article_id, $address_id)
    {
        return DB::table('address_article')
            ->where('article_id', (int) $article_id)
            ->where('address_id', (int) $address_id)
            ->orderBy('id')
            ->first(['id', 'stock_min', 'stock_max']);
    }

    /**
     * Si la sucursal existe, es del dueño y no es un domicilio de comprador. Con la memoria de la
     * corrida se consulta UNA vez por (dueño, sucursal).
     *
     * @param  int  $address_id
     * @param  int  $owner_id
     * @param  \ArrayObject|null  $memoria
     * @return bool
     */
    protected static function sucursal_es_del_dueno($address_id, $owner_id, $memoria = null)
    {
        $clave = 'sucursal_min_max:' . (int) $owner_id . ':' . (int) $address_id;

        if (!is_null($memoria) && $memoria->offsetExists($clave)) {

            return (bool) $memoria[$clave];
        }

        $es_del_dueno = Address::sucursales_de($owner_id)->where('id', (int) $address_id)->exists();

        if (!is_null($memoria)) {

            $memoria[$clave] = $es_del_dueno;
        }

        return $es_del_dueno;
    }

    /**
     * Deja un aviso en el log UNA vez por corrida y por clave (sin memoria, en cada llamada). Mismo
     * criterio que CatalogoPorListaHelper::avisar_una_vez().
     *
     * @param  \ArrayObject|null  $memoria
     * @param  string  $clave
     * @param  string  $mensaje
     * @return void
     */
    protected static function avisar_una_vez($memoria, $clave, $mensaje)
    {
        if (!is_null($memoria)) {

            $clave_del_aviso = 'aviso_min_max:' . $clave;

            if ($memoria->offsetExists($clave_del_aviso)) {

                return;
            }

            $memoria[$clave_del_aviso] = true;
        }

        Log::warning($mensaje);
    }
}
