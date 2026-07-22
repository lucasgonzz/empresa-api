<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\Helpers\article\ArticlePricesHelper;

/**
 * Helper del "contexto Vender" del buscador general.
 *
 * `VenderController::search_nombre` no es una simple busqueda de texto: ademas del criterio,
 * excluye insumos, busca coincidencia exacta de codigo de barras (articulo y variantes) cuando el
 * criterio es una sola palabra, y expande las variantes de cada articulo en filas propias con su
 * propio precio, imagenes y depositos. Esta clase saca esa logica del controller para que
 * `SearchController::globalSearch` pueda aplicarla cuando la llamada declara venir del modulo de
 * Vender (o de un pedido a proveedor / receta), a traves de un flag `contexto` en el request.
 *
 * `search_nombre` sigue vivo (respaldo del frontend viejo) y ahora delega en estos mismos metodos,
 * asi que su comportamiento y su respuesta no cambian.
 */
class VenderSearchHelper
{
    /**
     * Whitelist estricta de contextos soportados por el buscador general para activar la logica
     * de Vender.
     */
    const CONTEXTOS_VALIDOS = ['vender', 'provider_order', 'recipe'];

    /**
     * Condiciones extra de la busqueda de articulos desde el modulo de Vender: excluir insumos.
     *
     * La coincidencia por codigo de barras (exacta, con una sola palabra, articulo y variantes) NO
     * se agrega aca: tiene que quedar DENTRO del mismo grupo OR de coincidencia de texto que arma
     * `GlobalSearchQueryHelper::apply` (para no romper el AND general de la query ni colarse como
     * una condicion aparte). Ver `bar_code_condition_callback` mas abajo, que devuelve el callback
     * que `SearchController::globalSearch` le pasa a `GlobalSearchQueryHelper::apply` para eso.
     *
     * @param \Illuminate\Database\Eloquent\Builder $models
     * @param string $query_value Criterio de texto (recibido por firma, no se usa en esta
     *        exclusion; se mantiene en la firma tal como lo pide el prompt para consistencia con
     *        el resto de los metodos de este helper).
     * @param string $contexto 'vender', 'provider_order' o 'recipe'.
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public static function apply_conditions($models, $query_value, $contexto)
    {
        // Los insumos se excluyen siempre, salvo que la busqueda venga de un pedido a proveedor o
        // de una receta (mismo criterio que $from_provider_order_or_recipe en search_nombre).
        if ($contexto !== 'provider_order' && $contexto !== 'recipe') {
            $models = $models->where(function ($subquery) {
                $subquery->where('es_insumo', 0)
                            ->orWhereNull('es_insumo');
            });
        }

        return $models;
    }

    /**
     * Callback de condiciones extra de texto para la coincidencia exacta de codigo de barras
     * (articulo y variantes). Pensado para pasarse como parametro opcional a
     * `GlobalSearchQueryHelper::apply`, que lo invoca DENTRO del mismo grupo OR de coincidencia de
     * texto (en modo estricto, un `orWhere` mas del grupo; en modo distribuido, un aporte mas al
     * "pozo comun" de la palabra), en vez de agregarlo como un AND aparte.
     *
     * Mismo criterio que `search_nombre`: solo aplica cuando el criterio de busqueda es una unica
     * palabra (con varias palabras, el buscador general ya cubre coincidencia parcial por props
     * normales como `bar_code`, si el frontend la manda).
     *
     * @return \Closure function(\Illuminate\Database\Eloquent\Builder $sub, array $keywords): void
     */
    public static function bar_code_condition_callback()
    {
        return function ($sub, $keywords) {
            // Solo aplica con una unica palabra en el criterio (coincidencia EXACTA, no LIKE).
            if (count($keywords) !== 1) {
                return;
            }

            // Unica palabra del criterio, usada como codigo de barras exacto.
            $keyword = $keywords[0];

            $sub->orWhere('bar_code', $keyword);
            $sub->orWhereHas('article_variants', function ($variant_query) use ($keyword) {
                $variant_query->where('bar_code', $keyword);
            });
        };
    }

    /**
     * Expande las variantes de cada articulo en filas propias, con su propio precio, imagenes y
     * depositos. Movimiento literal del bloque `article_variants` de `search_nombre`: mismas
     * claves, mismo orden de casos (coincidencia exacta por barcode de variante primero, despues
     * las variantes por descripcion, despues el articulo sin variantes).
     *
     * Si el cliente no tiene la extension `article_variants`, devuelve la coleccion tal cual.
     *
     * @param \Illuminate\Support\Collection $articles Articulos ya traidos con `get()` (withAll,
     *        con la relacion `article_variants` cargada).
     * @param string $query_value Criterio de texto original (se vuelve a separar en palabras, igual
     *        que `search_nombre`).
     * @return \Illuminate\Support\Collection
     */
    public static function expand_variants($articles, $query_value)
    {
        // Sin la extension de variantes, no hay nada para expandir: se devuelve la coleccion tal
        // cual llego (mismo comportamiento que search_nombre).
        if (!UserHelper::hasExtencion('article_variants')) {
            return $articles;
        }

        // Misma extension usada en search_nombre para decidir si el codigo de barras (de articulo
        // y de variantes) participa de la coincidencia de palabras sueltas y del match exacto.
        $search_bar_code_en_vender = UserHelper::hasExtencion('search_bar_code_en_vender');

        // Palabras del criterio de busqueda, mismo criterio de separacion que search_nombre.
        $keywords = explode(' ', trim($query_value));

        // Resultado final: arranca como la misma coleccion de articulos (search_nombre hace lo
        // mismo: $results = $articles, ambas variables apuntan a la misma instancia de Collection).
        $results = $articles;

        foreach ($articles as $article) {

            // Detectar que palabras de la busqueda coincidieron con el nombre, codigo o barcode del
            // articulo/variante.
            $matched_keywords = collect($keywords)->filter(function ($word) use ($article, $search_bar_code_en_vender) {
                $word_lower = mb_strtolower($word, 'UTF-8');

                if (strpos(
                        mb_strtolower($article->name ?? '', 'UTF-8'),
                        $word_lower
                    ) !== false ||
                    strpos(
                        mb_strtolower($article->provider_code ?? '', 'UTF-8'),
                        $word_lower
                    ) !== false) {
                    return true;
                }

                if ($search_bar_code_en_vender) {
                    if (strpos(
                            mb_strtolower($article->bar_code ?? '', 'UTF-8'),
                            $word_lower
                        ) !== false) {
                        return true;
                    }

                    foreach ($article->article_variants as $variant) {
                        if (strpos(
                                mb_strtolower($variant->bar_code ?? '', 'UTF-8'),
                                $word_lower
                            ) !== false) {
                            return true;
                        }
                    }
                }

                return false;
            })->values();

            // Palabras restantes para buscar dentro de variant_description.
            $remaining_keywords = array_diff($keywords, $matched_keywords->toArray());

            // Si el articulo tiene variantes.
            if ($article->article_variants->count() > 0) {

                // Coincidencia exacta por barcode de variante: devolver solo esa variante.
                if ($search_bar_code_en_vender && count($keywords) === 1) {
                    $keyword = $keywords[0];
                    $matching_variants_by_bar_code = $article->article_variants->filter(function ($variant) use ($keyword) {
                        return ($variant->bar_code ?? '') === $keyword;
                    });

                    if ($matching_variants_by_bar_code->count() > 0) {
                        foreach ($matching_variants_by_bar_code as $variant) {

                            $variant_final_price = self::get_variant_price($variant);

                            $results->push((object)[
                                'is_variant'            => true,
                                'id'                    => $variant->article->id,
                                'variant_id'            => $variant->id,
                                'variant_description'   => $variant->variant_description,
                                'final_price'           => $variant_final_price,
                                // Capa 3 (Prompt 263): desglose por metodo de pago con precio_base_incluye_tarjeta.
                                'precios_por_metodo_pago' => ArticlePricesHelper::calcular_precios_por_metodo_pago_con_tarjeta_incluida($variant_final_price, UserHelper::userId()),
                                'price_types'           => $article->price_types,
                                'bar_code'              => $variant->bar_code,
                                'name'                  => $article->name. ' '.$variant->variant_description,
                                'article'               => $article,
                                'images'                => self::get_variant_images($variant),
                                'addresses'             => $variant->addresses,
                            ]);
                        }

                        continue;
                    }
                }

                // Filtrar variantes que coincidan con todas las palabras restantes.
                $matching_variants = $article->article_variants->filter(function ($variant) use ($remaining_keywords) {
                    foreach ($remaining_keywords as $word) {
                        if (strpos(
                                mb_strtolower($variant->variant_description ?? '', 'UTF-8'),
                                mb_strtolower($word, 'UTF-8')
                            ) === false) {
                            return false;
                        }
                    }
                    return true;
                });

                if ($matching_variants->count() > 0) {
                    foreach ($matching_variants as $variant) {

                        $variant_final_price = self::get_variant_price($variant);

                        $results->push((object)[
                            'is_variant'            => true,
                            'id'                    => $variant->article->id,
                            'variant_id'            => $variant->id,
                            'variant_description'   => $variant->variant_description,
                            'final_price'           => $variant_final_price,
                            // Capa 3 (Prompt 263): desglose por metodo de pago con precio_base_incluye_tarjeta.
                            'precios_por_metodo_pago' => ArticlePricesHelper::calcular_precios_por_metodo_pago_con_tarjeta_incluida($variant_final_price, UserHelper::userId()),
                            'price_types'           => $article->price_types,
                            'bar_code'              => $variant->bar_code,
                            'name'                  => $article->name. ' '.$variant->variant_description,
                            'article'               => $article,
                            'images'                => self::get_variant_images($variant),
                            'addresses'             => $variant->addresses,
                        ]);
                    }
                }

            } else {
                // Si no tiene variantes, y al menos una keyword matcheo, agregar el articulo.
                if ($matched_keywords->isNotEmpty()) {
                    $article->is_variant = false;
                    // Capa 3 (Prompt 263, hotfix Prompt 313): precios_por_metodo_pago viene del
                    // accessor del modelo Article, no hace falta asignarlo aca.
                    $results->push($article);
                }
            }
        }

        return $results;
    }

    /**
     * Indica si un contexto recibido por request es uno de los soportados por el buscador general
     * para activar la logica de Vender.
     *
     * @param string|null $contexto
     * @return bool
     */
    public static function is_valid_contexto($contexto)
    {
        return in_array($contexto, self::CONTEXTOS_VALIDOS, true);
    }

    /**
     * Imagenes a usar para una variante: las propias si tiene `image_url`, si no las del articulo.
     * Movido literal desde `VenderController::get_variant_images`.
     *
     * @param \App\Models\ArticleVariant $variant
     * @return mixed
     */
    private static function get_variant_images($variant)
    {
        $images = $variant->article->images;
        if (!is_null($variant->image_url)) {
            $images = [
                [
                    env('IMAGE_URL_PROP_NAME', 'image_url') => $variant->image_url,
                ]
            ];
        }
        return $images;
    }

    /**
     * Precio final de una variante: el propio si tiene `price`, si no el `final_price` del
     * articulo. Movido literal desde `VenderController::get_variant_price`.
     *
     * @param \App\Models\ArticleVariant $variant
     * @return float|int|null
     */
    private static function get_variant_price($variant)
    {
        $final_price = $variant->article->final_price;

        if (!is_null($variant->price)) {
            $final_price = $variant->price;
        }

        return $final_price;
    }
}
