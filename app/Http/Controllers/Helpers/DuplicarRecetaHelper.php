<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\InventoryLinkageHelper;
use App\Http\Controllers\Helpers\article\ArticleUbicationsHelper;
use App\Http\Controllers\Helpers\article\BarCodeAutomaticoHelper;
use App\Models\Recipe;
use App\Models\RecipeRoute;
use Illuminate\Support\Facades\DB;

/**
 * Duplicar un modelo completo: crea EL ARTICULO NUEVO Y SU RECETA en un solo paso.
 *
 * Es para el caso de Quino: 20 modelos de silla x 6 artículos cada uno, donde entre un modelo y
 * el siguiente lo único que cambia son las cantidades. Cargarlo a mano son 120 altas de artículo
 * más 20 recetas con sus rutas y sus insumos.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 *  QUÉ SE COPIA Y QUÉ NO, Y POR QUÉ
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 *
 * Se copia TODO el artículo original con replicate() y después se pisan explícitamente los
 * campos que no se pueden heredar. Copiar y borrar, y no enumerar 30 campos: `articles` tiene
 * más de 90 columnas y una lista blanca se queda vieja el mes que viene, cuando alguien agregue
 * una columna nueva y nadie se acuerde de sumarla acá.
 *
 * NO se heredan, agrupados por el motivo:
 *
 *   IDENTIDAD FÍSICA DEL PRODUCTO — `bar_code`, `provider_code`, `sku`, `plu`,
 *   `provider_article_id`. Un código de barras identifica un producto físico distinto:
 *   duplicarlo hace que el lector de la caja levante el artículo equivocado. Lo mismo con la
 *   clave del proveedor y el código de balanza.
 *
 *   IDENTIDAD DE UNA PUBLICACIÓN ONLINE — `me_li_id`, `mercado_libre`, `meli_category_id`,
 *   `meli_category_name`, `listing_type_id`, `meli_listing_type_id`, `meli_buying_mode_id`,
 *   `meli_item_condition_id`, `meli_descripcion`, `need_sync_to_meli`,
 *   `tiendanube_product_id`, `tiendanube_variant_id`, `handle`, `disponible_tienda_nube`,
 *   `precio_promocional`, `seo_title`, `seo_description`, `video_url`. Son ids de publicaciones
 *   concretas: copiarlos apunta dos artículos de la base a la MISMA publicación de Mercado Libre
 *   o de Tienda Nube, y a partir de ahí cualquier sincronización pisa la publicación del otro.
 *
 *   NÚMEROS Y FECHAS QUE NO SON DEL ARTÍCULO NUEVO — `num` y `stock` quedan en null porque es
 *   exactamente lo que hace `ArticleController@store` (las dos líneas están comentadas ahí): el
 *   alta normal deja el artículo sin número y sin stock. `stock_updated_at` y
 *   `final_price_updated_at` son fechas de hechos que le pasaron al original, no al duplicado.
 *
 *   DERIVADOS DEL TEXTO — `embedding`, `embedding_generated_at`, `embedding_source_hash`. El
 *   embedding del original describe el nombre del original. Se regeneran solos.
 *
 * 🔴 `needs_sync_with_tn = 0` ES UNA DECISIÓN, Y ES REVERSIBLE DE UN CARÁCTER.
 * `ArticleController@store` lo pone en 1. Acá va en 0 a propósito: este botón crea artículos de
 * a docenas desde la pantalla de producción, y publicar 20 subproductos intermedios en la tienda
 * online sin que nadie los haya mirado es un efecto que el usuario no pidió. `articles` es una
 * tabla COMPARTIDA con el ecommerce del mismo cliente, así que crear filas acá se ve allá. Si
 * Lucas prefiere que se comporte igual que el alta normal, es cambiar el 0 por un 1.
 *
 * `es_insumo` SÍ se copia tal cual: si el original era insumo, el duplicado también, y queda
 * fuera del listado general por el mismo criterio.
 *
 * 🔴 NO SE CREA NINGÚN StockMovement. El artículo nuevo arranca sin stock, igual que uno creado
 * por el alta normal. Ningún módulo de este sistema cambia stock a mano.
 *
 * Todo corre adentro de una transacción: si falla la copia de la receta, no queda un artículo
 * huérfano dando vueltas en el listado.
 */
class DuplicarRecetaHelper
{
    /**
     * @param  int     $recipe_id        La receta que se duplica.
     * @param  string  $nombre_nuevo     Nombre del artículo (y de la receta) nuevos.
     * @param  object  $controller_instance
     * @return \App\Models\Recipe        La receta nueva.
     */
    public static function duplicar($recipe_id, $nombre_nuevo, $controller_instance)
    {
        return DB::transaction(function () use ($recipe_id, $nombre_nuevo, $controller_instance) {

            /*
             * 🔴 EL FILTRO POR user_id NO ES OPCIONAL. El id llega crudo por la URL
             * (POST /api/recipe/{id}/duplicar) y sin este where cualquier comercio se copiaba
             * la receta de otro: el articulo entero con sus costos, sus listas de precio y la
             * receta con todas sus rutas, cantidades e insumos, a su propia cuenta y persistido.
             * findOrFail() sobre el builder filtrado sigue devolviendo 404, que es lo correcto:
             * una receta de otra cuenta no existe para esta.
             */
            $recipe = Recipe::where('user_id', $controller_instance->userId())
                            ->with('article.price_types', 'recipe_routes.articles')
                            ->findOrFail($recipe_id);

            if (is_null($recipe->article)) {
                abort(422, 'La receta no tiene un artículo asociado.');
            }

            $nuevo = self::duplicar_articulo($recipe->article, $nombre_nuevo, $controller_instance);

            $nueva_receta = Recipe::create([
                'num'                       => $controller_instance->num('recipes'),
                'name'                      => $nuevo->name,
                'article_id'                => $nuevo->id,
                'article_cost_from_recipe'  => $recipe->article_cost_from_recipe,
                'address_id'                => $recipe->address_id,
                'observations'              => $recipe->observations,
                'user_id'                   => $controller_instance->userId(),
            ]);

            foreach ($recipe->recipe_routes as $ruta) {
                self::duplicar_ruta($ruta, $nueva_receta);
            }

            return $nueva_receta;
        });
    }

    /**
     * Los insumos de la receta duplicada QUE A SU VEZ TIENEN RECETA PROPIA.
     *
     * ─────────────────────────────────────────────────────────────────────────────────────────
     *  🔴 EL DUPLICAR NO RE-APUNTA LOS INSUMOS, Y NO PUEDE HACERLO.
     * ─────────────────────────────────────────────────────────────────────────────────────────
     *
     * Duplicando "Silla 1" → "Silla 2", la receta nueva sigue consumiendo *Estructura silla 1*,
     * *Asiento silla 1*, *Respaldo de madera silla 1*. Para una receta hoja (patas → caño) eso
     * está PERFECTO: el caño es el mismo caño. Para una de ensamble está mal.
     *
     * Re-apuntar solo se puede si el sistema sabe cuál es la parte equivalente del otro modelo,
     * y no lo sabe: no hay ninguna relación entre "Estructura silla 1" y "Estructura silla 2",
     * ni siquiera existe la segunda hasta que alguien la cargue. Adivinar por nombre —buscar el
     * artículo que más se parezca— es peor que no hacer nada: engancharía la parte equivocada
     * en silencio, y el error se descubre cuando el lote descuenta el stock de otro modelo.
     *
     * Lo que sí se puede, y es lo que se hace, es MATAR EL SILENCIO: se devuelven nombrados los
     * insumos que son productos fabricados (los que tienen receta propia), que son exactamente
     * los que hay que mirar. La materia prima no se lista porque no hay nada que revisar ahí.
     *
     * @param  \App\Models\Recipe  $recipe   La receta NUEVA.
     * @param  int                 $user_id
     * @return array  Cada entrada: ['article_id' => int, 'article_name' => string]
     */
    public static function insumos_a_revisar($recipe, $user_id)
    {
        $recipe->load('recipe_routes.articles');

        $vistos = [];
        $insumos = [];

        foreach ($recipe->recipe_routes as $ruta) {

            foreach ($ruta->articles as $insumo) {

                $article_id = (int) $insumo->id;

                // El mismo artículo puede ser insumo de la misma ruta más de una vez (en estados
                // distintos) y de más de una ruta: se avisa una sola vez.
                if (isset($vistos[$article_id])) {
                    continue;
                }

                $vistos[$article_id] = true;

                $tiene_receta_propia = Recipe::where('user_id', $user_id)
                                            ->where('article_id', $article_id)
                                            ->exists();

                if ($tiene_receta_propia) {
                    $insumos[] = [
                        'article_id'    => $article_id,
                        'article_name'  => $insumo->name,
                    ];
                }
            }
        }

        return $insumos;
    }

    /**
     * El artículo nuevo, con sus side-effects de alta.
     *
     * @param  \App\Models\Article  $original
     * @param  string               $nombre_nuevo
     * @param  object               $controller_instance
     * @return \App\Models\Article
     */
    private static function duplicar_articulo($original, $nombre_nuevo, $controller_instance)
    {
        $user_id = $controller_instance->userId();

        $nuevo = $original->replicate();

        $nuevo->name = ucfirst($nombre_nuevo);

        // El slug es el único campo del sistema que se desduplica (agregando -1, -2...), y lo
        // hace contra los artículos del mismo user_id.
        $nuevo->slug = ArticleHelper::slug($nombre_nuevo, null, $user_id);

        // Identidad física del producto: ver el encabezado.
        $nuevo->bar_code            = null;
        $nuevo->provider_code       = null;
        $nuevo->sku                 = null;
        $nuevo->plu                 = null;
        $nuevo->provider_article_id = null;

        // Número y stock: el alta normal los deja en null y acá se replica ese flujo.
        $nuevo->num                     = null;
        $nuevo->stock                   = null;
        $nuevo->stock_updated_at        = null;
        $nuevo->final_price_updated_at  = null;

        // Identidad de la publicación en Mercado Libre.
        $nuevo->me_li_id                = null;
        $nuevo->mercado_libre           = null;
        $nuevo->meli_category_id        = null;
        $nuevo->meli_category_name      = null;
        $nuevo->listing_type_id         = null;
        $nuevo->meli_listing_type_id    = null;
        $nuevo->meli_buying_mode_id     = null;
        $nuevo->meli_item_condition_id  = null;
        $nuevo->meli_descripcion        = null;
        $nuevo->need_sync_to_meli       = 0;

        // Identidad de la publicación en la tienda online (base compartida con el ecommerce).
        $nuevo->tiendanube_product_id   = null;
        $nuevo->tiendanube_variant_id   = null;
        $nuevo->handle                  = null;
        $nuevo->disponible_tienda_nube  = 0;
        $nuevo->precio_promocional      = null;
        $nuevo->seo_title               = null;
        $nuevo->seo_description         = null;
        $nuevo->video_url               = null;

        // DECISIÓN reversible (ver el encabezado): el duplicado NO se publica solo en la tienda.
        $nuevo->needs_sync_with_tn = 0;

        // Derivados del texto del artículo: describen al original, no al nuevo.
        $nuevo->embedding               = null;
        $nuevo->embedding_generated_at  = null;
        $nuevo->embedding_source_hash   = null;

        $nuevo->user_id = $user_id;

        $nuevo->save();

        /*
         * Los side-effects del alta, en el mismo orden y con las mismas llamadas que
         * ArticleController@store, para no inventar un segundo camino de alta que después se
         * comporte distinto.
         */

        // En cuentas sin la extensión `codigos_de_barra_por_defecto` es un no-op; en las que la
        // tienen, le pone su PROPIO id como código, que es lo correcto para un producto distinto.
        BarCodeAutomaticoHelper::set_bar_code($nuevo);

        self::copiar_price_types($original, $nuevo);

        ArticleHelper::setFinalPrice($nuevo);

        ArticleUbicationsHelper::init_ubications($nuevo);

        // En cuentas sin `inventory_linkages` es un no-op.
        $inventory_linkage_helper = new InventoryLinkageHelper();
        $inventory_linkage_helper->checkArticle($nuevo);

        return $nuevo->fresh();
    }

    /**
     * Copia el pivot `article_price_type` del artículo original al nuevo.
     *
     * 🔴 A mano y no con `ArticlePriceTypeHelper::attach_price_types()`: ese helper hace foreach
     * sobre lo que le pasan sin chequear null, y acá no hay ningún request con listas de precio
     * — los precios salen del artículo original. Llamarlo con null revienta.
     *
     * @param  \App\Models\Article  $original
     * @param  \App\Models\Article  $nuevo
     * @return void
     */
    private static function copiar_price_types($original, $nuevo)
    {
        foreach ($original->price_types as $price_type) {
            $nuevo->price_types()->attach($price_type->id, [
                'percentage'                     => $price_type->pivot->percentage,
                'price'                          => $price_type->pivot->price,
                'final_price'                    => $price_type->pivot->final_price,
                'previus_final_price'            => $price_type->pivot->previus_final_price,
                'incluir_en_excel_para_clientes' => $price_type->pivot->incluir_en_excel_para_clientes,
                'setear_precio_final'            => $price_type->pivot->setear_precio_final,
                'precio_luego_de_recargos'       => $price_type->pivot->precio_luego_de_recargos,
                'monto_ganancia'                 => $price_type->pivot->monto_ganancia,
            ]);
        }
    }

    /**
     * Copia una ruta de la receta original a la receta nueva, con todos sus insumos.
     *
     * @param  \App\Models\RecipeRoute  $original
     * @param  \App\Models\Recipe       $nueva_receta
     * @return \App\Models\RecipeRoute
     */
    private static function duplicar_ruta($original, $nueva_receta)
    {
        $nueva_ruta = RecipeRoute::create([
            'recipe_id'                         => $nueva_receta->id,
            'recipe_route_type_id'              => $original->recipe_route_type_id,
            'is_default'                        => $original->is_default,
            'from_address_id'                   => $original->from_address_id,
            'to_address_id'                     => $original->to_address_id,
            'notes'                             => $original->notes,
            'end_order_production_status_id'    => $original->end_order_production_status_id,
            'order_production_status_group_id'  => $original->order_production_status_group_id,
            // temporal_id queda null: identifica una ruta a medio cargar en el formulario de la
            // SPA, no tiene nada que ver con una ruta ya guardada.
            'temporal_id'                       => null,
        ]);

        /*
         * ⚠️ Un attach() por renglón y NUNCA un sync(): el mismo artículo puede ser insumo de la
         * misma ruta más de una vez, en estados distintos (el electrodo de "soldado" y el de
         * "pre-armado" son dos renglones), y un sync() los colapsaría en uno solo — o sea que la
         * receta duplicada consumiría menos insumo que la original sin que nadie lo note.
         */
        foreach ($original->articles as $insumo) {
            $nueva_ruta->articles()->attach($insumo->id, [
                'amount'                        => $insumo->pivot->amount,
                'notes'                         => $insumo->pivot->notes,
                'order_production_status_id'    => $insumo->pivot->order_production_status_id,
                'address_id'                    => $insumo->pivot->address_id,
            ]);
        }

        return $nueva_ruta;
    }
}
