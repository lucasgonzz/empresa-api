<?php

namespace App\Http\Controllers\Helpers\import\article\motor;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Relaciones y registros de la importación de artículos, escritos y leídos EN LOTE.
 *
 * Misión `importacion-excel-motor-rapido` (24/9/2026), constructor B1. Agrupa lo que
 * `ActualizarBBDD` y `ArticleImportHelper::update_article_import_result()` hacían con una
 * consulta por artículo (o por par artículo × relación), dejando en la base exactamente lo mismo:
 *
 *  - el tracking de diffs para el rollback: los `SELECT` de "estado previo" de
 *    `article_discounts` / `article_surchages` / `article_price_type` / `article_provider` pasan a
 *    UN `whereIn` por relación y por lote; el `old`/`new` que se registra es el mismo;
 *  - `article_provider` de los creados con proveedor: un `upsert` multi-fila sobre el índice único
 *    `uniq_article_provider` (misma fila que dejaba `syncWithoutDetaching`: `provider_code`,
 *    `cost`, timestamps);
 *  - `article_article_ubication` de los creados: UN `INSERT` multi-fila (misma fila que `attach()`:
 *    sin timestamps, porque la relación no declara `withTimestamps`);
 *  - `article_actualizados_article_import_result`: UN `INSERT` multi-fila con el mismo JSON de
 *    `updated_props` que armaba el `attach()` por artículo (la fila nace sin timestamps, como con
 *    el pivot custom que usa la relación).
 *
 * Es una clase de funciones puras sobre la base: no guarda estado.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 */
class RelacionesEnLote
{
    /** Tamaño de las tandas de los INSERT / upsert multi-fila. */
    const TANDA = 500;

    /** Tamaño de las tandas del pivot del historial (cada fila lleva un JSON entero). */
    const TANDA_HISTORIAL = 200;

    // ─────────────────────────────────────────────────────────────────────────────────────────
    // Tracking de diffs (lecturas del estado previo, para el rollback)
    // ─────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Valores previos de `article_discounts` o `article_surchages` (columna `percentage` o
     * `amount`) para varios artículos en UNA consulta. Mismo resultado que el
     * `where('article_id')->whereNotNull($column)->pluck($column)` por artículo de antes:
     * floats, en orden de id, y un array vacío para el artículo que no tiene filas.
     *
     * @param  string $table       'article_discounts' | 'article_surchages'
     * @param  string $column      'percentage' | 'amount'
     * @param  array  $article_ids
     * @return array  article_id => [float, …]
     */
    public static function valores_previos($table, $column, array $article_ids)
    {
        $previos = [];

        foreach ($article_ids as $article_id) {
            $previos[(int) $article_id] = [];
        }

        if (count($article_ids) === 0) {
            return $previos;
        }

        foreach (array_chunk(array_keys($previos), self::TANDA) as $tanda) {

            $filas = DB::table($table)
                        ->whereIn('article_id', $tanda)
                        ->whereNotNull($column)
                        ->orderBy('id')
                        ->get(['article_id', $column]);

            foreach ($filas as $fila) {
                $previos[(int) $fila->article_id][] = (float) $fila->{$column};
            }
        }

        return $previos;
    }

    /**
     * Pivots previos de `article_price_type` para varios pares (artículo, lista) en UNA consulta.
     * Mismo resultado que el `where(article_id)->where(price_type_id)->first()` por par de antes:
     * la primera fila del par por id (la tabla no tiene índice único), o ausente si no hay.
     *
     * @param  array $pares [[article_id, price_type_id], …]
     * @return array "article_id-price_type_id" => stdClass (fila del pivot)
     */
    public static function pivots_de_listas_previos(array $pares)
    {
        return self::pivots_previos('article_price_type', 'price_type_id', $pares);
    }

    /**
     * Pivots previos de `article_provider` para varios pares (artículo, proveedor) en UNA
     * consulta. Mismo resultado que el `first()` por par de antes.
     *
     * @param  array $pares [[article_id, provider_id], …]
     * @return array "article_id-provider_id" => stdClass (fila del pivot)
     */
    public static function pivots_de_proveedor_previos(array $pares)
    {
        return self::pivots_previos('article_provider', 'provider_id', $pares);
    }

    /**
     * Lectura común de los dos métodos de arriba.
     *
     * @param  string $table
     * @param  string $columna_par   nombre de la segunda columna del par
     * @param  array  $pares
     * @return array
     */
    protected static function pivots_previos($table, $columna_par, array $pares)
    {
        $previos = [];

        if (count($pares) === 0) {
            return $previos;
        }

        $article_ids = [];
        $otros_ids   = [];
        $buscados    = [];

        foreach ($pares as $par) {
            $article_ids[(int) $par[0]] = true;
            $otros_ids[(int) $par[1]]   = true;
            $buscados[(int) $par[0].'-'.(int) $par[1]] = true;
        }

        foreach (array_chunk(array_keys($article_ids), self::TANDA) as $tanda) {

            /*
             * El whereIn doble trae también pares cruzados que nadie pidió (artículo A con la lista
             * de B): se filtran en PHP contra los pares buscados. El orderBy('id') garantiza que,
             * si un par está repetido en la tabla, se quede la primera fila como con first().
             */
            $filas = DB::table($table)
                        ->whereIn('article_id', $tanda)
                        ->whereIn($columna_par, array_keys($otros_ids))
                        ->orderBy('id')
                        ->get();

            foreach ($filas as $fila) {

                $clave = (int) $fila->article_id.'-'.(int) $fila->{$columna_par};

                if (!isset($buscados[$clave]) || isset($previos[$clave])) {
                    continue;
                }

                $previos[$clave] = $fila;
            }
        }

        return $previos;
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    // Escrituras en bloque
    // ─────────────────────────────────────────────────────────────────────────────────────────

    /**
     * `ActualizarBBDD::set_articles_providers()` en bloque: por cada artículo creado con
     * `provider_id`, la fila (article_id, provider_id, provider_code, cost) en `article_provider`.
     *
     * Misma fila que dejaba `syncWithoutDetaching([$provider_id => [provider_code, cost]])`: si el
     * par no existe se inserta con `created_at`/`updated_at`; si existe (índice único
     * `uniq_article_provider`, migración 2026_08_16_100500) se actualizan `provider_code`, `cost` y
     * `updated_at`. Llamarlo dos veces no duplica ni lanza.
     *
     * @param  iterable $articulos_creados_models  modelos Article con id real
     * @return int cantidad de filas enviadas
     */
    public static function vincular_proveedores_de_creados($articulos_creados_models)
    {
        $ahora = Carbon::now()->format('Y-m-d H:i:s');
        $filas = [];

        foreach ($articulos_creados_models as $article) {

            if (!$article->provider_id) {
                continue;
            }

            $filas[] = [
                'article_id'    => (int) $article->id,
                'provider_id'   => (int) $article->provider_id,
                'provider_code' => $article->provider_code,
                'cost'          => $article->cost,
                'created_at'    => $ahora,
                'updated_at'    => $ahora,
            ];
        }

        foreach (array_chunk($filas, self::TANDA) as $tanda) {
            DB::table('article_provider')->upsert(
                $tanda,
                ['article_id', 'provider_id'],
                ['provider_code', 'cost', 'updated_at']
            );
        }

        return count($filas);
    }

    /**
     * `ArticleUbicationsHelper::init_ubications()` para todos los creados en bloque: una fila
     * (article_id, article_ubication_id) por artículo y ubicación del comercio, en
     * `article_article_ubication`. Misma fila que `attach($ubication->id)`: sin timestamps (la
     * relación `article_ubications()` no declara `withTimestamps`).
     *
     * @param  iterable $articulos_creados_models
     * @param  iterable $ubications  ArticleUbication del comercio
     * @return int cantidad de filas insertadas
     */
    public static function vincular_ubicaciones($articulos_creados_models, $ubications)
    {
        $filas = [];

        foreach ($articulos_creados_models as $article) {
            foreach ($ubications as $ubication) {
                $filas[] = [
                    'article_id'           => (int) $article->id,
                    'article_ubication_id' => (int) $ubication->id,
                ];
            }
        }

        foreach (array_chunk($filas, self::TANDA) as $tanda) {
            DB::table('article_article_ubication')->insert($tanda);
        }

        return count($filas);
    }

    /**
     * El pivot `article_actualizados_article_import_result` del chunk en UN INSERT multi-fila
     * (por tandas), en vez de un `attach()` por artículo actualizado.
     *
     * Misma fila que dejaba `$import_result->articulos_actualizados()->attach($id, ['updated_props'
     * => json])`: `updated_props` = el array del artículo SIN la clave `id`, serializado con
     * `JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION` (con los `__diff__` y todo lo demás
     * tal cual viene); una fila por ENTRADA (si el mismo artículo aparece dos veces, dos filas,
     * como hoy); las entradas sin `id` se saltean; `created_at`/`updated_at` quedan en null (el
     * pivot custom de la relación no tiene timestamps).
     *
     * @param  int      $import_result_id
     * @param  iterable $articulos_actualizados  arrays (o modelos con ArrayAccess) con clave 'id'
     * @return int cantidad de filas insertadas
     */
    public static function registrar_actualizados_en_el_chunk($import_result_id, $articulos_actualizados)
    {
        $filas = [];

        foreach ($articulos_actualizados as $article) {

            if (empty($article['id'])) {
                continue;
            }

            // Se clona y se saca 'id' para guardar sólo props y diffs (igual que antes).
            $props = $article;
            unset($props['id']);

            $filas[] = [
                'article_import_result_id' => (int) $import_result_id,
                'article_id'               => (int) $article['id'],
                'updated_props'            => json_encode($props, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION),
            ];
        }

        foreach (array_chunk($filas, self::TANDA_HISTORIAL) as $tanda) {
            DB::table('article_actualizados_article_import_result')->insert($tanda);
        }

        return count($filas);
    }
}
