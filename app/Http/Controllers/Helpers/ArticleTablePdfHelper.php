<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\CommonLaravel\SearchController;
use App\Models\Article;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Qué artículos van al PDF tabular del catálogo (ArticleController::tablePdf) y EN QUÉ ORDEN.
 *
 * El orden es el punto: hasta la misión catalogo-pdf-encabezado (18/9/2026) tablePdf() terminaba
 * con orderBy('created_at', 'DESC') y pisaba tanto el orden que el usuario había elegido en el
 * listado (las flechas del header, que viajan como ordenar_de en los filtros) como el orden en
 * que la SPA manda los seleccionados. Acá los ids se resuelven en orden y los modelos se
 * devuelven en ese mismo orden, sin que la consulta vuelva a ordenar nada.
 */
class ArticleTablePdfHelper
{
    /**
     * Ids de artículos a imprimir, EN ORDEN y sin repetidos.
     *
     * - `articles_id` no vacío (seleccionados): "12-7-30" en el orden en que la SPA los mandó;
     *   ceros, basura y repetidos se descartan conservando la primera aparición.
     * - Si no, `filters` (filtrados): la misma búsqueda que el listado
     *   (SearchController::search), que ya aplica ordenar_de vía ColumnFiltersHelper y desempata
     *   por created_at DESC, id DESC. Se toman los ids tal como vinieron.
     * - Sin ninguno de los dos: [].
     *
     * @param  \Illuminate\Http\Request  $request
     * @return int[]
     */
    public static function resolve_article_ids(Request $request): array
    {
        $articles_id = $request->query('articles_id');

        if ($request->has('articles_id') && ! is_null($articles_id) && $articles_id !== '') {
            return self::unique_positive_ids(explode('-', (string) $articles_id));
        }

        if ($request->has('filters')) {
            $filters = json_decode($request->query('filters'), true);
            $models = (new SearchController())->search($request, 'article', $filters);

            return self::unique_positive_ids($models->pluck('id')->toArray());
        }

        return [];
    }

    /**
     * Carga los artículos con las relaciones que usa el PDF y los devuelve en el orden de $ids.
     *
     * La consulta NO tiene orderBy a propósito: el orden que importa es el de $ids (el del
     * filtrado o el de la selección), y un orderBy('created_at', 'DESC') acá era justamente lo
     * que lo pisaba (misión catalogo-pdf-encabezado, 18/9/2026). whereIn() no garantiza ningún
     * orden, así que la colección se reordena por la posición de cada id.
     *
     * @param  int[]        $ids            Ids en el orden en que tienen que imprimirse.
     * @param  int          $user_id        Dueño de los artículos (userId() del controller).
     * @param  mixed        $price_type_id  Lista de precios opcional (`?price_type_id=`).
     * @return \Illuminate\Support\Collection
     */
    public static function load_articles_in_order(array $ids, $user_id, $price_type_id): Collection
    {
        $ids = self::unique_positive_ids($ids);

        if (! count($ids)) {
            return new EloquentCollection();
        }

        $article_with = [
            'category',
            'sub_category',
            'brand',
            'provider',
            'iva',
            'unidad_medida',
            'images' => function ($query) {
                $query->orderBy('id', 'asc');
            },
        ];

        /** Lista de precios opcional para resolver `article_final_price` desde el pivot. */
        if (! is_null($price_type_id) && $price_type_id !== '' && UserHelper::uses_listas_de_precio()) {
            $article_with[] = 'price_types';
        }

        $articles = Article::where('user_id', $user_id)
            ->whereIn('id', $ids)
            ->with($article_with)
            ->get();

        /** id => posición pedida; lo que no esté (no debería pasar) va al final. */
        $position_by_id = array_flip($ids);

        return $articles->sortBy(function ($article) use ($position_by_id) {
            return $position_by_id[$article->id] ?? PHP_INT_MAX;
        })->values();
    }

    /**
     * Enteros positivos sin repetidos, conservando la primera aparición de cada uno.
     *
     * @param  array  $raw_ids
     * @return int[]
     */
    protected static function unique_positive_ids(array $raw_ids): array
    {
        $ids = [];
        foreach ($raw_ids as $raw_id) {
            if (is_array($raw_id) || is_object($raw_id)) {
                continue;
            }
            $id = (int) $raw_id;
            if ($id <= 0 || isset($ids[$id])) {
                continue;
            }
            $ids[$id] = $id;
        }

        return array_values($ids);
    }
}
