<?php

namespace App\Http\Controllers\Helpers\article;

use Carbon\Carbon;

/**
 * Reglas de la sincronización del caché de artículos de VENDER sin conexión (misión
 * ingreso-sin-internet, 23/10/2026).
 *
 * La SPA (`offline/sync_articles.js`) mantiene en IndexedDB una copia de los artículos VENDIBLES
 * del comercio, y la pone al día con dos endpoints, en este orden:
 *
 *   1. `GET article/index/eliminados` (`ArticleController::index_deleted()`): todo lo que viene
 *      ahí se BORRA del caché (solo usa el `id`).
 *   2. `GET article/index/from-status` (`ArticleController::index()`): todo lo que viene ahí se
 *      GUARDA en el caché (`bulkPut`). Trae solo lo vendible: `status = 'active'` y `es_insumo` 0 o
 *      null, y con `updated_after` solo lo tocado después de esa marca.
 *
 * Se midieron dos huecos, y cada uno tiene su método acá:
 *
 *   - LA MARCA. La SPA guardaba como `updated_after` de la próxima vuelta el reloj DEL EQUIPO al
 *     TERMINAR la descarga. Un artículo modificado mientras se descargaban las páginas (o con el
 *     reloj del equipo adelantado) quedaba con `updated_at` anterior a la marca y no volvía a
 *     bajar nunca. Ahora la marca la da el servidor, tomada ANTES de la consulta. Ver
 *     `marca_de_sincronizacion()`.
 *   - LOS QUE DEJAN DE SER VENDIBLES. Un artículo pasado a inactivo o a insumo no está borrado, así
 *     que no venía en (1), y ya no es vendible, así que no venía en (2): quedaba para siempre en el
 *     caché y se podía vender sin conexión. Ahora (1) los trae también. Ver
 *     `aplicar_criterio_de_no_vendibles()`.
 *
 * PHP 7.4: sin match, str_contains, ?->, argumentos nombrados ni union types.
 */
class SincronizacionOfflineArticulosHelper {

    /** Formato de la marca: el mismo que la SPA ya guardaba y que MySQL compara con `updated_at`. */
    const FORMATO_MARCA = 'Y-m-d H:i:s';

    /**
     * La marca `sincronizado_hasta` que devuelve `index()`: la hora del servidor tomada ANTES de
     * ejecutar la consulta, en la zona de la app, un segundo para atrás.
     *
     * 🔴 ANTES de la consulta: todo lo que se modifique después de este instante tiene
     * `updated_at` posterior, así que entra en la próxima vuelta (`updated_at > marca`). Tomada
     * después, un artículo modificado mientras corría la consulta podría quedar afuera de las dos.
     *
     * 🔴 UN SEGUNDO PARA ATRÁS: `updated_at` guarda segundos enteros y el filtro de `index()` es
     * estricto (`>`). Un artículo modificado en el mismo segundo de la marca pero DESPUÉS de la
     * consulta queda con `updated_at` igual a la marca, y `>` lo dejaría afuera para siempre. Con
     * un segundo de margen, la próxima vuelta lo trae. El costo es volver a bajar lo que se tocó en
     * ese segundo, que el `bulkPut` de la SPA pisa sin efecto.
     *
     * En `config('app.timezone')` (America/Argentina/Buenos_Aires), que es la zona en la que
     * Laravel escribe `updated_at`: la comparación es entre horas de la misma zona.
     *
     * @return string  `Y-m-d H:i:s`.
     */
    static function marca_de_sincronizacion() {

        return Carbon::now(config('app.timezone'))->subSecond()->format(Self::FORMATO_MARCA);
    }

    /**
     * Restringe la consulta de `index_deleted()` (que ya viene con `withTrashed()` y el
     * `user_id`) a lo que la SPA tiene que sacar del caché desde `updated_after`:
     *
     *   a) los BORRADOS desde la marca (`deleted_at >= updated_after`), como siempre;
     *   b) los NO borrados que dejaron de ser vendibles y se tocaron después de la marca
     *      (`updated_at > updated_after`).
     *
     * 🔴 (b) ES EXACTAMENTE EL COMPLEMENTO DE LO QUE `index()` INCLUYE, y no un criterio parecido.
     * `index()` incluye `status = 'active' AND (es_insumo = 0 OR es_insumo IS NULL)`. Su negación es
     * `status <> 'active' OR es_insumo <> 0`:
     *   - `status` es un enum NOT NULL con default 'active' (`active`, `inactive`,
     *     `from_provider_order`, `from_budget`, migración `2019_10_24_002337`), así que no hay un
     *     NULL que la desigualdad deje afuera: los tres valores que no son 'active' entran;
     *   - `es_insumo` es un boolean nullable (`2026_03_19_150352`): 0 y NULL son vendibles, y
     *     `es_insumo <> 0` deja afuera justamente a los dos (NULL <> 0 no es verdadero), así que
     *     entra solo el 1 (o cualquier otro valor distinto de 0, que `index()` tampoco incluye).
     * Si `index()` cambia su criterio, este tiene que cambiar con él.
     *
     * El `updated_at` estricto es el mismo comparador que usa `index()` para su lado. No se mira
     * `final_price_updated_at`: que cambie el precio no cambia si el artículo es vendible.
     *
     * Sin `updated_after` (primera sincronización, caché vacío) no se llama: el endpoint sigue
     * devolviendo solo los borrados, como siempre.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query          `Article::withTrashed()` del comercio.
     * @param  string                                 $updated_after  La marca de la vuelta anterior.
     * @return \Illuminate\Database\Eloquent\Builder
     */
    static function aplicar_criterio_de_no_vendibles($query, $updated_after) {

        return $query->where(function ($q) use ($updated_after) {

            // a) Borrados desde la marca: lo de siempre.
            $q->where(function ($borrados) use ($updated_after) {
                $borrados->whereNotNull('deleted_at')
                         ->where('deleted_at', '>=', $updated_after);
            })
            // b) Vivos que dejaron de ser vendibles después de la marca.
            ->orWhere(function ($no_vendibles) use ($updated_after) {
                $no_vendibles->whereNull('deleted_at')
                             ->where('updated_at', '>', $updated_after)
                             ->where(function ($criterio) {
                                 $criterio->where('status', '!=', 'active')
                                          ->orWhere('es_insumo', '!=', 0);
                             });
            });
        });
    }
}
