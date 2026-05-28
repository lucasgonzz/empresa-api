<?php

namespace App\Http\Controllers\Helpers\sale;

use App\Models\TableColumnPreference;

/**
 * Carga condicional de imágenes en artículos de ventas según preferencia de columnas (belongs_to_many).
 */
class SaleArticlesEagerLoadHelper
{
    /** Modelo relacionado guardado en table_column_preferences (store del front). */
    const PREFERENCE_MODEL_NAME = 'article';

    /** Tipo de preferencia: relación sale → articles. */
    const PREFERENCE_TYPE = 'btm_sale_articles';

    /**
     * Indica si el usuario tiene visible la columna images del artículo en la tabla de ventas.
     *
     * @param int $user_id ID del owner (user_id de las ventas).
     * @return bool
     */
    static function user_wants_sale_article_images($user_id)
    {
        $preference = TableColumnPreference::where('user_id', $user_id)
            ->where('model_name', self::PREFERENCE_MODEL_NAME)
            ->where('preference_type', self::PREFERENCE_TYPE)
            ->first();

        if (is_null($preference) || !is_array($preference->columns)) {
            return false;
        }

        foreach ($preference->columns as $column) {
            if (!self::column_is_visible($column)) {
                continue;
            }
            if (self::column_is_article_images($column)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array $column Fila de preferencia guardada en JSON.
     * @return bool
     */
    static function column_is_visible($column)
    {
        return isset($column['visible']) && (bool) $column['visible'];
    }

    /**
     * Columna images del modelo article (no del pivot).
     *
     * @param array $column
     * @return bool
     */
    static function column_is_article_images($column)
    {
        $key = isset($column['key']) ? $column['key'] : '';
        if ($key !== 'images') {
            return false;
        }

        if (isset($column['row_id']) && $column['row_id'] === 'model_prop:images') {
            return true;
        }

        $source = isset($column['source']) ? $column['source'] : 'model_prop';

        return $source === 'model_prop';
    }

    /**
     * Agrega eager load de articles.images al query de Sale si la preferencia lo pide.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $user_id
     * @return \Illuminate\Database\Eloquent\Builder
     */
    static function apply_images_if_preferred($query, $user_id)
    {
        if (!self::user_wants_sale_article_images($user_id)) {
            return $query;
        }

        return $query->withSaleArticlesImages();
    }
}
