<?php

namespace App\Http\Controllers\Helpers\database;

use App\Models\Article;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Borrado físico del inventario de artículos de un usuario y todas sus dependencias en BD.
 */
class LimpiarInventarioHelper
{
    /**
     * Catálogo de tablas/relaciones que se limpian al ejecutar limpiar_inventario.
     * Orden de eliminación: primero hijos, al final articles.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function get_relations_catalog()
    {
        return [
            // --- Variantes y propiedades ---
            [
                'key' => 'address_article_variant',
                'table' => 'address_article_variant',
                'label' => 'Stock por variante en depósito (address_article_variant)',
                'type' => 'article_variant_id',
            ],
            [
                'key' => 'article_property_value_article_variant',
                'table' => 'article_property_value_article_variant',
                'label' => 'Valores de propiedad por variante',
                'type' => 'article_variant_id',
            ],
            [
                'key' => 'article_variants',
                'table' => 'article_variants',
                'label' => 'Variantes de artículo',
                'type' => 'article_id',
            ],
            [
                'key' => 'article_property_article_property_value',
                'table' => 'article_property_article_property_value',
                'label' => 'Pivot propiedad ↔ valor de propiedad',
                'type' => 'article_property_id',
            ],
            [
                'key' => 'article_properties',
                'table' => 'article_properties',
                'label' => 'Propiedades del artículo',
                'type' => 'article_id',
            ],
            // --- Stock, precios y datos directos del artículo ---
            [
                'key' => 'stock_movements',
                'table' => 'stock_movements',
                'label' => 'Movimientos de stock',
                'type' => 'article_id',
            ],
            [
                'key' => 'article_deposit_movement',
                'table' => 'article_deposit_movement',
                'label' => 'Movimientos artículo ↔ depósito',
                'type' => 'article_id',
            ],
            [
                'key' => 'price_changes',
                'table' => 'price_changes',
                'label' => 'Historial de cambios de precio',
                'type' => 'article_id',
            ],
            [
                'key' => 'descriptions',
                'table' => 'descriptions',
                'label' => 'Descripciones',
                'type' => 'article_id',
            ],
            [
                'key' => 'article_discounts',
                'table' => 'article_discounts',
                'label' => 'Descuentos',
                'type' => 'article_id',
            ],
            [
                'key' => 'article_discount_blancos',
                'table' => 'article_discount_blancos',
                'label' => 'Descuentos en blanco',
                'type' => 'article_id',
            ],
            [
                'key' => 'article_surchages',
                'table' => 'article_surchages',
                'label' => 'Recargos',
                'type' => 'article_id',
            ],
            [
                'key' => 'article_surchage_blancos',
                'table' => 'article_surchage_blancos',
                'label' => 'Recargos en blanco',
                'type' => 'article_id',
            ],
            [
                'key' => 'article_price_ranges',
                'table' => 'article_price_ranges',
                'label' => 'Rangos de precio',
                'type' => 'article_id',
            ],
            [
                'key' => 'article_purchases',
                'table' => 'article_purchases',
                'label' => 'Compras / costos de reposición',
                'type' => 'article_id',
            ],
            [
                'key' => 'article_performances',
                'table' => 'article_performances',
                'label' => 'Rendimiento de artículo',
                'type' => 'article_id',
            ],
            [
                'key' => 'article_inventory_performance',
                'table' => 'article_inventory_performance',
                'label' => 'Rendimiento de inventario',
                'type' => 'article_id',
            ],
            [
                'key' => 'article_price_type_monedas',
                'table' => 'article_price_type_monedas',
                'label' => 'Precios por moneda',
                'type' => 'article_id',
            ],
            [
                'key' => 'advises',
                'table' => 'advises',
                'label' => 'Avisos de stock mínimo',
                'type' => 'article_id',
            ],
            [
                'key' => 'questions',
                'table' => 'questions',
                'label' => 'Preguntas (ecommerce)',
                'type' => 'article_id',
            ],
            [
                'key' => 'messages',
                'table' => 'messages',
                'label' => 'Mensajes vinculados al artículo',
                'type' => 'article_id',
            ],
            // --- Pivots y relaciones many-to-many ---
            [
                'key' => 'address_article',
                'table' => 'address_article',
                'label' => 'Stock por depósito (address_article)',
                'type' => 'article_id',
            ],
            [
                'key' => 'article_tag',
                'table' => 'article_tag',
                'label' => 'Etiquetas',
                'type' => 'article_id',
            ],
            [
                'key' => 'article_color',
                'table' => 'article_color',
                'label' => 'Colores',
                'type' => 'article_id',
            ],
            [
                'key' => 'article_size',
                'table' => 'article_size',
                'label' => 'Talles',
                'type' => 'article_id',
            ],
            [
                'key' => 'article_deposit',
                'table' => 'article_deposit',
                'label' => 'Depósitos (pivot)',
                'type' => 'article_id',
            ],
            [
                'key' => 'article_provider',
                'table' => 'article_provider',
                'label' => 'Proveedores del artículo',
                'type' => 'article_id',
            ],
            [
                'key' => 'article_cart',
                'table' => 'article_cart',
                'label' => 'Carritos de compra',
                'type' => 'article_id',
            ],
            [
                'key' => 'article_prices_list',
                'table' => 'article_prices_list',
                'label' => 'Listas de precios',
                'type' => 'article_id',
            ],
            [
                'key' => 'article_price_type',
                'table' => 'article_price_type',
                'label' => 'Tipos de precio',
                'type' => 'article_id',
            ],
            [
                'key' => 'article_article_ubication',
                'table' => 'article_article_ubication',
                'label' => 'Ubicaciones en depósito',
                'type' => 'article_id',
            ],
            [
                'key' => 'article_article_price_type_group',
                'table' => 'article_article_price_type_group',
                'label' => 'Grupos de tipos de precio',
                'type' => 'article_id',
            ],
            [
                'key' => 'article_promocion_vinoteca',
                'table' => 'article_promocion_vinoteca',
                'label' => 'Promociones vinoteca',
                'type' => 'article_id',
            ],
            // --- Ventas, presupuestos, pedidos ---
            [
                'key' => 'article_sale',
                'table' => 'article_sale',
                'label' => 'Líneas de venta (article_sale)',
                'type' => 'article_id',
            ],
            [
                'key' => 'sale_article_attachments',
                'table' => 'sale_article_attachments',
                'label' => 'Adjuntos en líneas de venta',
                'type' => 'article_id',
            ],
            [
                'key' => 'sale_article_additions',
                'table' => 'sale_article_additions',
                'label' => 'Adiciones en líneas de venta',
                'type' => 'article_id',
            ],
            [
                'key' => 'article_budget',
                'table' => 'article_budget',
                'label' => 'Presupuestos',
                'type' => 'article_id',
            ],
            [
                'key' => 'budget_product_article_stocks',
                'table' => 'budget_product_article_stocks',
                'label' => 'Stock en productos de presupuesto',
                'type' => 'article_id',
            ],
            [
                'key' => 'article_provider_order',
                'table' => 'article_provider_order',
                'label' => 'Pedidos a proveedor',
                'type' => 'article_id',
            ],
            [
                'key' => 'article_order',
                'table' => 'article_order',
                'label' => 'Pedidos ecommerce (orders)',
                'type' => 'article_id',
            ],
            [
                'key' => 'article_commerce_order',
                'table' => 'article_commerce_order',
                'label' => 'Pedidos comercio',
                'type' => 'article_id',
            ],
            [
                'key' => 'article_current_acount',
                'table' => 'article_current_acount',
                'label' => 'Cuenta corriente por artículo',
                'type' => 'article_id',
            ],
            [
                'key' => 'article_sale_modification_antes',
                'table' => 'article_sale_modification_antes_de_actualizar',
                'label' => 'Modificación de venta (antes)',
                'type' => 'article_id',
            ],
            [
                'key' => 'article_sale_modification_despues',
                'table' => 'article_sale_modification_despues_de_actualizar',
                'label' => 'Modificación de venta (después)',
                'type' => 'article_id',
            ],
            [
                'key' => 'acopio_article_delivery_article',
                'table' => 'acopio_article_delivery_article',
                'label' => 'Entregas de acopio',
                'type' => 'article_id',
            ],
            // --- Combos ---
            [
                'key' => 'article_combo',
                'table' => 'article_combo',
                'label' => 'Composición de combos (article_id y combo_id)',
                'type' => 'article_or_combo',
            ],
            [
                'key' => 'combo_sale',
                'table' => 'combo_sale',
                'label' => 'Ventas de combos',
                'type' => 'combo_id',
            ],
            // --- Producción y recetas ---
            [
                'key' => 'production_movements',
                'table' => 'production_movements',
                'label' => 'Movimientos de producción',
                'type' => 'article_id',
            ],
            [
                'key' => 'production_batch_movement_inputs',
                'table' => 'production_batch_movement_inputs',
                'label' => 'Insumos en lotes de producción',
                'type' => 'article_id',
            ],
            [
                'key' => 'production_batch_movements',
                'table' => 'production_batch_movements',
                'label' => 'Movimientos de lote de producción',
                'type' => 'production_batch_scope',
            ],
            [
                'key' => 'production_batches',
                'table' => 'production_batches',
                'label' => 'Lotes de producción',
                'type' => 'article_id',
            ],
            [
                'key' => 'article_recipe_route',
                'table' => 'article_recipe_route',
                'label' => 'Rutas de receta por artículo',
                'type' => 'article_id',
            ],
            [
                'key' => 'recipe_routes',
                'table' => 'recipe_routes',
                'label' => 'Rutas de receta',
                'type' => 'recipe_scope',
            ],
            [
                'key' => 'article_recipe',
                'table' => 'article_recipe',
                'label' => 'Insumos en recetas (pivot)',
                'type' => 'article_or_recipe',
            ],
            [
                'key' => 'recipes',
                'table' => 'recipes',
                'label' => 'Recetas',
                'type' => 'recipes_table',
            ],
            [
                'key' => 'article_order_production',
                'table' => 'article_order_production',
                'label' => 'Órdenes de producción',
                'type' => 'article_id',
            ],
            [
                'key' => 'article_order_production_finished',
                'table' => 'article_order_production_finished',
                'label' => 'Órdenes de producción finalizadas',
                'type' => 'article_id',
            ],
            // --- Importaciones e integraciones ---
            [
                'key' => 'article_articles_pre_import',
                'table' => 'article_articles_pre_import',
                'label' => 'Pre-importación',
                'type' => 'article_id',
            ],
            [
                'key' => 'article_creados_import_history',
                'table' => 'article_creados_import_history',
                'label' => 'Historial importación (creados)',
                'type' => 'article_id',
            ],
            [
                'key' => 'article_actualizados_import_history',
                'table' => 'article_actualizados_import_history',
                'label' => 'Historial importación (actualizados)',
                'type' => 'article_id',
            ],
            [
                'key' => 'article_creados_article_import_result',
                'table' => 'article_creados_article_import_result',
                'label' => 'Resultado importación (creados)',
                'type' => 'article_id',
            ],
            [
                'key' => 'article_actualizados_article_import_result',
                'table' => 'article_actualizados_article_import_result',
                'label' => 'Resultado importación (actualizados)',
                'type' => 'article_id',
            ],
            [
                'key' => 'stock_suggestion_articles',
                'table' => 'stock_suggestion_articles',
                'label' => 'Sugerencias de stock',
                'type' => 'article_id',
            ],
            [
                'key' => 'sync_to_t_n_articles',
                'table' => 'sync_to_t_n_articles',
                'label' => 'Cola sync Tienda Nube',
                'type' => 'article_id',
            ],
            [
                'key' => 'sync_to_meli_articles',
                'table' => 'sync_to_meli_articles',
                'label' => 'Cola sync Mercado Libre',
                'type' => 'article_id',
            ],
            [
                'key' => 'sync_from_meli_article_article',
                'table' => 'sync_from_meli_article_article',
                'label' => 'Pivot importación desde Meli',
                'type' => 'article_id',
            ],
            [
                'key' => 'article_meli_attribute',
                'table' => 'article_meli_attribute',
                'label' => 'Atributos Meli',
                'type' => 'article_id',
            ],
            [
                'key' => 'meli_order_article',
                'table' => 'meli_order_article',
                'label' => 'Pedidos Meli',
                'type' => 'article_id',
            ],
            [
                'key' => 'article_me_li_order',
                'table' => 'article_me_li_order',
                'label' => 'Pedidos Meli (legacy)',
                'type' => 'article_id',
            ],
            [
                'key' => 'article_tienda_nube_order',
                'table' => 'article_tienda_nube_order',
                'label' => 'Pedidos Tienda Nube',
                'type' => 'article_id',
            ],
            // --- Polimórficas ---
            [
                'key' => 'images',
                'table' => 'images',
                'label' => 'Imágenes (polimórfico imageable)',
                'type' => 'polymorphic_image',
            ],
            [
                'key' => 'views',
                'table' => 'views',
                'label' => 'Vistas (polimórfico viewable)',
                'type' => 'polymorphic_view',
            ],
            // --- Tabla principal (última) ---
            [
                'key' => 'articles_provider_article_id',
                'table' => 'articles',
                'label' => 'Referencias provider_article_id en otros artículos (se anulan)',
                'type' => 'nullify_provider_article_id',
            ],
            [
                'key' => 'articles',
                'table' => 'articles',
                'label' => 'Artículos (borrado físico, incluye soft-deleted)',
                'type' => 'articles_delete',
            ],
        ];
    }

    /**
     * Obtiene los IDs de artículos del usuario (activos y eliminados con soft delete).
     *
     * @param int $user_id
     * @return \Illuminate\Support\Collection
     */
    public static function get_article_ids($user_id)
    {
        return Article::withTrashed()
            ->where('user_id', $user_id)
            ->pluck('id');
    }

    /**
     * Cuenta registros de una relación del catálogo para el inventario del usuario.
     *
     * @param array<string, mixed> $relation
     * @param \Illuminate\Support\Collection $article_ids
     * @param int $user_id
     * @return int
     */
    public static function count_relation(array $relation, $article_ids, $user_id)
    {
        if (!Schema::hasTable($relation['table'])) {
            return 0;
        }

        if ($article_ids->isEmpty() && $relation['type'] !== 'articles_delete') {
            return 0;
        }

        $query = self::build_delete_query($relation, $article_ids, $user_id);

        if ($relation['type'] === 'nullify_provider_article_id') {
            return (int) $query->count();
        }

        if ($relation['type'] === 'articles_delete') {
            return (int) Article::withTrashed()->where('user_id', $user_id)->count();
        }

        return (int) $query->count();
    }

    /**
     * Elimina registros de una relación del catálogo.
     *
     * @param array<string, mixed> $relation
     * @param \Illuminate\Support\Collection $article_ids
     * @param int $user_id
     * @return int Filas afectadas
     */
    public static function delete_relation(array $relation, $article_ids, $user_id)
    {
        if (!Schema::hasTable($relation['table'])) {
            return 0;
        }

        if ($article_ids->isEmpty() && !in_array($relation['type'], ['articles_delete'], true)) {
            return 0;
        }

        $query = self::build_delete_query($relation, $article_ids, $user_id);

        if ($relation['type'] === 'nullify_provider_article_id') {
            return (int) $query->update(['provider_article_id' => null]);
        }

        if ($relation['type'] === 'articles_delete') {
            return (int) DB::table('articles')->where('user_id', $user_id)->delete();
        }

        return (int) $query->delete();
    }

    /**
     * Arma el query de borrado/conteo según el tipo de relación.
     *
     * @param array<string, mixed> $relation
     * @param \Illuminate\Support\Collection $article_ids
     * @param int $user_id
     * @return \Illuminate\Database\Query\Builder
     */
    protected static function build_delete_query(array $relation, $article_ids, $user_id)
    {
        $table = $relation['table'];
        $type = $relation['type'];

        if ($type === 'article_id') {
            return DB::table($table)->whereIn('article_id', $article_ids);
        }

        if ($type === 'combo_id') {
            return DB::table($table)->whereIn('combo_id', $article_ids);
        }

        if ($type === 'article_or_combo') {
            return DB::table($table)->where(function ($q) use ($article_ids) {
                $q->whereIn('article_id', $article_ids)
                    ->orWhereIn('combo_id', $article_ids);
            });
        }

        if ($type === 'article_variant_id') {
            $variant_ids = DB::table('article_variants')->whereIn('article_id', $article_ids)->pluck('id');

            return DB::table($table)->whereIn('article_variant_id', $variant_ids);
        }

        if ($type === 'article_property_id') {
            $property_ids = DB::table('article_properties')->whereIn('article_id', $article_ids)->pluck('id');

            return DB::table($table)->whereIn('article_property_id', $property_ids);
        }

        if ($type === 'recipe_scope') {
            $recipe_ids = self::get_recipe_ids($article_ids, $user_id);

            return DB::table($table)->whereIn('recipe_id', $recipe_ids);
        }

        if ($type === 'recipes_table') {
            $recipe_ids = self::get_recipe_ids($article_ids, $user_id);

            return DB::table($table)->whereIn('id', $recipe_ids);
        }

        if ($type === 'article_or_recipe') {
            $recipe_ids = self::get_recipe_ids($article_ids, $user_id);

            return DB::table($table)->where(function ($q) use ($article_ids, $recipe_ids) {
                $q->whereIn('article_id', $article_ids);
                if ($recipe_ids->isNotEmpty()) {
                    $q->orWhereIn('recipe_id', $recipe_ids);
                }
            });
        }

        if ($type === 'production_batch_scope') {
            $batch_ids = DB::table('production_batches')->whereIn('article_id', $article_ids)->pluck('id');

            return DB::table($table)->whereIn('production_batch_id', $batch_ids);
        }

        if ($type === 'polymorphic_image') {
            return DB::table($table)->whereIn('imageable_id', $article_ids)
                ->where(function ($q) {
                    $q->where('imageable_type', 'App\Models\Article')
                        ->orWhere('imageable_type', 'like', '%Article%');
                });
        }

        if ($type === 'polymorphic_view') {
            return DB::table($table)->whereIn('viewable_id', $article_ids)
                ->where(function ($q) {
                    $q->where('viewable_type', 'App\Models\Article')
                        ->orWhere('viewable_type', 'App\View')
                        ->orWhere('viewable_type', 'like', '%Article%');
                });
        }

        if ($type === 'nullify_provider_article_id') {
            return DB::table('articles')->whereIn('provider_article_id', $article_ids);
        }

        if ($type === 'articles_delete') {
            return DB::table('articles')->where('user_id', $user_id);
        }

        return DB::table($table)->whereRaw('0 = 1');
    }

    /**
     * IDs de recetas del usuario o vinculadas a sus artículos.
     *
     * @param \Illuminate\Support\Collection $article_ids
     * @param int $user_id
     * @return \Illuminate\Support\Collection
     */
    protected static function get_recipe_ids($article_ids, $user_id)
    {
        if (!Schema::hasTable('recipes')) {
            return collect();
        }

        return DB::table('recipes')
            ->where(function ($q) use ($article_ids, $user_id) {
                $q->where('user_id', $user_id);
                if ($article_ids->isNotEmpty()) {
                    $q->orWhereIn('article_id', $article_ids);
                }
            })
            ->pluck('id');
    }
}
