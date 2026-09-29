<?php

namespace Tests\Feature\Precios\RecalculoEnLote;

use Illuminate\Support\Facades\DB;

/**
 * La foto de todo lo que un recálculo de precios puede escribir, para comparar dos caminos campo
 * por campo (misión recalculo-precios-motor-rapido, 28/9/2026). La usan RecalculoEnLoteTestCase y
 * el test de equivalencia del rollback de importaciones, que extiende la base de los tests de
 * importación (Tests\Import\ImportTestCase) y no la de esta carpeta.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 */
trait FotoDePrecios
{
    /* ------------------------------------------------------------------------------------------
     * La foto
     * ---------------------------------------------------------------------------------------- */

    /**
     * Todo lo que el recálculo puede escribir, para los artículos dados: las filas de `articles`
     * (todas las columnas menos el vector de embeddings), las de article_price_type y
     * article_price_type_monedas (todas las columnas menos los ids de fila), y los price_changes
     * creados después de $marca con sus filas de price_change_price_type (todas las columnas menos
     * los ids generados). Los decimales se comparan como los devuelve la base.
     *
     * @param  array $ids
     * @param  int   $marca  Id máximo de price_changes antes de las corridas.
     * @return array
     */
    protected function foto(array $ids, $marca)
    {
        $foto = [
            'articles'   => [],
            'pivots'     => [],
            'monedas'    => [],
            'cambios'    => [],
        ];

        $columnas = [];

        foreach (DB::select('SHOW COLUMNS FROM `articles`') as $columna) {
            if ($columna->Field !== 'embedding') {
                $columnas[] = $columna->Field;
            }
        }

        foreach (DB::table('articles')->whereIn('id', $ids)->orderBy('id')->get($columnas) as $fila) {
            $foto['articles'][(int) $fila->id] = (array) $fila;
        }

        /* Pivots como lista (sin id de fila), ordenada: así un duplicado se ve. */
        $pivots = [];

        foreach (DB::table('article_price_type')->whereIn('article_id', $ids)->get() as $fila) {
            $fila = (array) $fila;
            unset($fila['id']);
            $pivots[] = $fila;
        }

        usort($pivots, function ($a, $b) {
            return strcmp(json_encode($a), json_encode($b));
        });

        $foto['pivots'] = $pivots;

        /* Las entradas por moneda ya existen antes de las corridas: su id es estable. */
        foreach (DB::table('article_price_type_monedas')->whereIn('article_id', $ids)->orderBy('id')->get() as $fila) {
            $foto['monedas'][(int) $fila->id] = (array) $fila;
        }

        /* price_changes de las corridas, por artículo y en orden, con sus listas. */
        $cambios = DB::table('price_changes')
                        ->where('id', '>', $marca)
                        ->whereIn('article_id', $ids)
                        ->orderBy('id')
                        ->get();

        $listas_por_cambio = [];

        if (count($cambios) > 0) {

            $filas_de_listas = DB::table('price_change_price_type')
                                    ->whereIn('price_change_id', $cambios->pluck('id')->all())
                                    ->get();

            foreach ($filas_de_listas as $fila) {
                $fila = (array) $fila;
                $price_change_id = (int) $fila['price_change_id'];
                unset($fila['id'], $fila['price_change_id']);
                $listas_por_cambio[$price_change_id][] = $fila;
            }
        }

        foreach ($cambios as $cambio) {

            $cambio = (array) $cambio;
            $price_change_id = (int) $cambio['id'];
            unset($cambio['id']);

            $listas = isset($listas_por_cambio[$price_change_id]) ? $listas_por_cambio[$price_change_id] : [];

            usort($listas, function ($a, $b) {
                return strcmp(json_encode($a), json_encode($b));
            });

            $cambio['listas'] = $listas;

            $foto['cambios'][(int) $cambio['article_id']][] = $cambio;
        }

        ksort($foto['cambios']);

        return $foto;
    }
}
