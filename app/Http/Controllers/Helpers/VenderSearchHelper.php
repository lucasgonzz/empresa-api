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
     * Callback POR PALABRA que extiende la propiedad `name` con la descripcion de las variantes
     * ("azul 36"), para que el criterio "zapatilla azul" encuentre el articulo "Zapatilla" cuyas
     * variantes se llaman "azul 35" y "azul 36".
     *
     * Por que hace falta: la fase SQL de la busqueda (`GlobalSearchQueryHelper::apply`) exige que
     * CADA palabra aparezca en alguna propiedad del articulo, y "azul" no esta en `name`: vive solo
     * en `article_variants.variant_description`. El articulo moria en SQL antes de que
     * `match_descriptors` -- que ya filtra bien las variantes por las palabras restantes -- llegara
     * a verlo. Por eso "zapatilla" funcionaba (la palabra esta en el nombre) y "zapatilla azul" no.
     * La fase SQL pasa a ser un SUPERCONJUNTO (cada palabra en el articulo o en alguna variante) y
     * el filtro fino sigue siendo `match_descriptors`.
     *
     * Decisiones de rendimiento -- no simplificar:
     * - Devuelve `null` si el comercio no tiene la extension `article_variants`: el SQL generado
     *   queda EXACTAMENTE igual al de antes, sin ninguna condicion de mas.
     * - Es un EXISTS correlacionado contra `article_variants.article_id` (indexado desde la
     *   migracion del 19/8/2026), no un IN con ids precalculados ni un JOIN: `article_variants` no
     *   tiene `user_id`, asi que cualquier scan propio de esa tabla mezclaria variantes de otros
     *   comercios en las bases compartidas. Colgado del articulo, el aislamiento sale gratis.
     *   El caller lo pone ULTIMO en el OR de cada palabra, asi MySQL solo lo evalua para los
     *   articulos que no matchearon ya por nombre o codigo.
     * - Solo variantes disponibles (`oculta = 0`): las ocultas no se ofrecen en Vender
     *   (`match_descriptors` las descarta) y un articulo no tiene que aparecer por una variante que
     *   despues no va a mostrar.
     *
     * @return \Closure|null function(\Illuminate\Database\Eloquent\Builder $sub, string $keyword): void
     *         `$sub` recibe un `orWhereHas` mas, para la palabra puntual `$keyword`.
     */
    public static function variant_description_condition_callback()
    {
        if (!UserHelper::hasExtencion('article_variants')) {
            return null;
        }

        return function ($sub, $keyword) {
            $sub->orWhereHas('article_variants', function ($variant_query) use ($keyword) {
                $variant_query->where('oculta', 0)
                              ->where('variant_description', 'LIKE', '%' . $keyword . '%');
            });
        };
    }

    /**
     * Decide que pares (articulo, variante|null) son el resultado de la busqueda, SIN construir el
     * objeto final de cada fila. Extraido de lo que antes era la mitad "decision" del loop de
     * `expand_variants()` (mismo orden de casos: coincidencia exacta por barcode de variante
     * primero, despues las variantes por descripcion, despues el articulo sin variantes) para que
     * los controllers puedan correrlo sobre una consulta LIVIANA (sin las 27 relaciones de
     * `withAllSinAcopio()`) y recien despues traer completos, con `build_row()`, solo los articulos
     * de la pagina que se va a mostrar.
     *
     * Si el cliente no tiene la extension `article_variants`, cada articulo de la coleccion es un
     * descriptor propio (mismo comportamiento que la version anterior de `expand_variants`, que
     * devolvia la coleccion tal cual sin filtrar nada mas).
     *
     * @param \Illuminate\Support\Collection $articles Articulos ya traidos con `get()`. Alcanza con
     *        `id, name, provider_code, bar_code` y, si la extension esta activa, `article_variants`
     *        con `id, article_id, bar_code, oculta, variant_description` -- no hace falta ninguna
     *        relacion pesada para decidir el matching.
     * @param string $query_value Criterio de texto original (se vuelve a separar en palabras, igual
     *        que `search_nombre`).
     * @return \Illuminate\Support\Collection Coleccion de objetos `{article_id, variant_id}`
     *         (`variant_id` es `null` para una fila de articulo sin variante), en el mismo orden en
     *         que hoy los arma `expand_variants()`.
     */
    public static function match_descriptors($articles, $query_value)
    {
        // Sin la extension de variantes, no hay nada que decidir: un descriptor por articulo, sin
        // filtrar nada (mismo comportamiento que la version anterior de expand_variants).
        if (!UserHelper::hasExtencion('article_variants')) {
            return $articles->map(function ($article) {
                return (object) ['article_id' => $article->id, 'variant_id' => null];
            })->values();
        }

        // Misma extension usada en search_nombre para decidir si el codigo de barras (de articulo
        // y de variantes) participa de la coincidencia de palabras sueltas y del match exacto.
        $search_bar_code_en_vender = UserHelper::hasExtencion('search_bar_code_en_vender');

        // Palabras del criterio de busqueda. Se separa por cualquier cantidad de espacios en blanco y
        // se descartan las vacias (la fase SQL, GlobalSearchQueryHelper::apply, tambien descarta las
        // vacias). Con explode(' ') un doble espacio -o un criterio vacio, que es una busqueda solo
        // por filtros fijos- dejaba una palabra '' y strpos(..., '') tira un warning en PHP 7.4 que
        // Laravel convierte en un 500 ("strpos(): Empty needle"): medido el 1/10/2026 con la
        // extension de variantes prendida.
        $keywords = preg_split('/\s+/', trim($query_value), -1, PREG_SPLIT_NO_EMPTY);

        // Sin palabras no hay nada que filtrar por texto: todo articulo es resultado.
        $sin_palabras = empty($keywords);

        $descriptors = collect();

        foreach ($articles as $article) {

            // Palabras de la busqueda que cubre el propio ARTICULO por si solo: su nombre, su codigo
            // de proveedor y (con la extension de codigo de barras) su codigo de barras. Valen para
            // TODAS sus variantes. El codigo de barras de una variante NO entra aca: cubre solo a esa
            // variante (ver mas abajo). Antes entraba, y como los codigos de variante nacen como
            // '0' + id ("01360"), un talle como "36" quedaba "cubierto" por el codigo de cualquier
            // variante y dejaba de filtrar a las demas: "zapatilla azul 36" devolvia las dos azules.
            $article_keywords = collect($keywords)->filter(function ($word) use ($article, $search_bar_code_en_vender) {
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
                }

                return false;
            })->values();

            // Palabras restantes: las que el articulo no cubre y por lo tanto tiene que cubrir cada
            // variante (en su descripcion o, con la extension, en su propio codigo de barras).
            $remaining_keywords = array_diff($keywords, $article_keywords->toArray());

            // Solo las variantes disponibles (oculta = false) se ofrecen para vender.
            // Portado del modulo de variantes de develop en el merge develop -> refractor.
            $available_variants = $article->article_variants->filter(function ($variant) {
                return !$variant->oculta;
            });

            // Si el articulo tiene variantes disponibles.
            if ($available_variants->count() > 0) {

                // Coincidencia exacta por barcode de variante: devolver solo esa variante.
                if ($search_bar_code_en_vender && count($keywords) === 1) {
                    $keyword = $keywords[0];
                    $matching_variants_by_bar_code = $available_variants->filter(function ($variant) use ($keyword) {
                        return ($variant->bar_code ?? '') === $keyword;
                    });

                    if ($matching_variants_by_bar_code->count() > 0) {
                        foreach ($matching_variants_by_bar_code as $variant) {
                            $descriptors->push((object) ['article_id' => $article->id, 'variant_id' => $variant->id]);
                        }

                        continue;
                    }
                }

                // Con la extension de codigo de barras, una palabra que aparece en la descripcion de
                // ALGUNA variante del articulo es una palabra de VARIANTE (color, talle): se exige en la
                // descripcion y no vale un codigo de barras que la contenga por casualidad ("36" dentro
                // de "01362", porque los codigos de variante nacen como '0' + id). Solo una palabra que
                // no esta en ninguna descripcion se interpreta como fragmento de codigo de barras.
                // Se mira TODAS las variantes, ocultas incluidas: el generador crea cada combinacion
                // nueva oculta hasta que el dueno la habilita, y "azul 36" oculta tambien dice que "36"
                // es un talle (si no, "zapatilla azul 36" devolveria "azul 35" por su codigo).
                $palabras_de_variante = [];

                if ($search_bar_code_en_vender) {
                    foreach ($remaining_keywords as $word) {
                        $word_lower = mb_strtolower($word, 'UTF-8');

                        $palabras_de_variante[$word] = $article->article_variants->contains(function ($variant) use ($word_lower) {
                            return strpos(mb_strtolower($variant->variant_description ?? '', 'UTF-8'), $word_lower) !== false;
                        });
                    }
                }

                // Filtrar variantes que coincidan con todas las palabras restantes: cada una tiene que
                // estar en la descripcion de la variante o, si no es una palabra de variante y la
                // extension esta activa, en su propio codigo de barras.
                $matching_variants = $available_variants->filter(function ($variant) use ($remaining_keywords, $search_bar_code_en_vender, $palabras_de_variante) {
                    foreach ($remaining_keywords as $word) {
                        $word_lower = mb_strtolower($word, 'UTF-8');

                        $en_la_descripcion = strpos(
                                mb_strtolower($variant->variant_description ?? '', 'UTF-8'),
                                $word_lower
                            ) !== false;

                        $en_su_codigo_de_barras = $search_bar_code_en_vender
                            && empty($palabras_de_variante[$word])
                            && strpos(
                                mb_strtolower($variant->bar_code ?? '', 'UTF-8'),
                                $word_lower
                            ) !== false;

                        if (!$en_la_descripcion && !$en_su_codigo_de_barras) {
                            return false;
                        }
                    }
                    return true;
                });

                foreach ($matching_variants as $variant) {
                    $descriptors->push((object) ['article_id' => $article->id, 'variant_id' => $variant->id]);
                }

            } else {
                // Si no tiene variantes disponibles, y al menos una keyword matcheo (o no hay criterio
                // de texto), agregar el articulo. "Matcheo" incluye, como siempre, el codigo de barras
                // de alguna de sus variantes (ocultas incluidas) con la extension de codigo de barras.
                $matcheo_algo = $article_keywords->isNotEmpty();

                if (!$matcheo_algo && $search_bar_code_en_vender) {
                    foreach ($article->article_variants as $variant) {
                        foreach ($keywords as $word) {
                            if (strpos(
                                    mb_strtolower($variant->bar_code ?? '', 'UTF-8'),
                                    mb_strtolower($word, 'UTF-8')
                                ) !== false) {
                                $matcheo_algo = true;
                                break 2;
                            }
                        }
                    }
                }

                if ($sin_palabras || $matcheo_algo) {
                    $descriptors->push((object) ['article_id' => $article->id, 'variant_id' => null]);
                }
            }
        }

        return $descriptors;
    }

    /**
     * Construye la fila final de un resultado de busqueda: el articulo tal cual (sin variante), o
     * el objeto plano con precio/imagenes/depositos propios de una variante. Extraido literal de lo
     * que antes era la mitad "construccion" del loop de `expand_variants()` -- las dos ramas de
     * variante (match exacto por barcode y match por descripcion) armaban el mismo objeto, ahora es
     * una sola funcion.
     *
     * Es la parte CARA de la busqueda (precio, imagenes, price_types, direcciones): los
     * controllers la llaman solo para los articulos de la pagina que se va a mostrar, nunca para
     * todos los que matchean el criterio.
     *
     * @param \App\Models\Article $article Articulo completo, con `withAllSinAcopio()` cargado.
     * @param \App\Models\ArticleVariant|null $variant Variante a mostrar, o `null` para la fila del
     *        articulo sin variante.
     * @return \App\Models\Article|object La fila de una variante es un objeto PLANO: ademas de lo propio
     *         de la variante (precio, stock, imagenes, depositos) lleva en la raiz el costo del articulo
     *         (`cost`, `costo_real`, `cost_in_dollars`, `presentacion`), que es lo que lee el guardado de la venta.
     */
    public static function build_row($article, $variant)
    {
        if (is_null($variant)) {
            // Capa 3 (Prompt 263, hotfix Prompt 313): precios_por_metodo_pago viene del accessor
            // del modelo Article, no hace falta asignarlo aca.
            $article->is_variant = false;
            return $article;
        }

        $variant_final_price = self::get_variant_price($variant);

        return (object) [
            'is_variant'              => true,
            'id'                      => $variant->article->id,
            'variant_id'              => $variant->id,
            'variant_description'     => $variant->variant_description,
            'final_price'             => $variant_final_price,
            // Capa 3 (Prompt 263): desglose por metodo de pago con precio_base_incluye_tarjeta.
            'precios_por_metodo_pago' => ArticlePricesHelper::calcular_precios_por_metodo_pago_con_tarjeta_incluida($variant_final_price, UserHelper::userId()),
            'price_types'             => $article->price_types,
            'bar_code'                => $variant->bar_code,
            // Stock de la variante (`article_variants.stock`, null si no tiene). Sin esta clave, con la
            // extension `check_article_stock_en_vender` la SPA bloquea TODAS las variantes con "Articulo
            // sin stock": `check_stock_mayor_a_cero` pregunta `item.stock === null || item.stock > 0` y
            // una fila sin `stock` queda `undefined`, que no es null ni es mayor a cero. Clave aditiva:
            // nada de lo que ya lee la fila cambia.
            'stock'                   => $variant->stock,
            // 🔴 Costo del ARTICULO y moneda de ese costo, en la RAIZ de la fila. NO sacarlas "porque
            // `article` ya las trae anidadas": el guardado de la venta lee de la raiz del item, no de
            // `item.article`. `SaleHelper::getCost` toma `costo_real` / `cost` / `presentacion` de la raiz
            // (la presentacion multiplica el costo en vinoteca) y
            // `CotizacionDeVentaHelper::item_esta_en_dolares` (y la conversion de precio de la SPA,
            // `convertir_precio_a_moneda_de_la_venta`) leen `cost_in_dollars` de la raiz. La SPA arma el
            // item de una variante elegida por nombre con esta fila tal cual: sin estas tres claves la
            // linea se guardaba con `cost` null y ganancia = precio, y el costo/precio de un articulo en
            // dolares no se convertia (mision variante-por-nombre-con-costo, 3/10/2026).
            // La variante no tiene costo propio (`article_variants` solo trae precio, stock, imagen y
            // codigo): el costo es el del articulo, igual que cuando se escanea el codigo de la variante
            // (donde la SPA arma `Object.assign({}, article, variant_row)`). Claves aditivas: la fila de
            // siempre no cambia, y el resto de lo que necesita el costo (`unidades_individuales`, `iva_id`)
            // la API ya lo lee de la base cuando el item no lo trae.
            'cost'                    => $article->cost,
            'costo_real'              => $article->costo_real,
            'cost_in_dollars'         => $article->cost_in_dollars,
            'presentacion'            => $article->presentacion,
            'name'                    => $article->name . ' ' . $variant->variant_description,
            'article'                 => $article,
            'images'                  => self::get_variant_images($variant),
            'addresses'               => $variant->addresses,
        ];
    }

    /**
     * Expande las variantes de cada articulo en filas propias, con su propio precio, imagenes y
     * depositos. Composicion de `match_descriptors()` + `build_row()`: mismo comportamiento exacto
     * que la version anterior (que tenia las dos mitades mezcladas en un solo loop), para cualquier
     * llamador que le pase una coleccion ya completa (con las 27 relaciones cargadas). Los
     * controllers de busqueda (`search_nombre`, `globalSearch`) YA NO llaman a este metodo: arman
     * el paginado real llamando `match_descriptors()` sobre una consulta liviana y `build_row()`
     * solo sobre los articulos de la pagina pedida. Se mantiene como referencia de equivalencia
     * (la usa el test de esta migracion) y por si algun llamador futuro necesita el camino simple
     * de una sola pasada.
     *
     * @param \Illuminate\Support\Collection $articles Articulos ya traidos con `get()` (withAll o
     *        withAllSinAcopio, con la relacion `article_variants` cargada).
     * @param string $query_value Criterio de texto original (se vuelve a separar en palabras, igual
     *        que `search_nombre`).
     * @return \Illuminate\Support\Collection
     */
    public static function expand_variants($articles, $query_value)
    {
        $descriptors = self::match_descriptors($articles, $query_value);
        $articles_by_id = $articles->keyBy('id');

        return $descriptors->map(function ($descriptor) use ($articles_by_id) {
            $article = $articles_by_id->get($descriptor->article_id);
            $variant = is_null($descriptor->variant_id)
                ? null
                : optional($article->article_variants)->firstWhere('id', $descriptor->variant_id);

            return self::build_row($article, $variant);
        })->values();
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
     * Clave fija 'hosting_url' (portado de develop en el sync del 4/8/2026): develop reemplazo
     * el env('IMAGE_URL_PROP_NAME', 'image_url') por la clave literal en la copia del controller,
     * que ya estaba extraida aca. Sin este port, el fix quedaba solo en codigo muerto.
     *
     * @param \App\Models\ArticleVariant $variant
     * @return mixed
     */
    public static function get_variant_images($variant)
    {
        $images = $variant->article->images;
        if (!is_null($variant->image_url)) {
            $images = [
                [
                    'hosting_url' => $variant->image_url,
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
    public static function get_variant_price($variant)
    {
        $final_price = $variant->article->final_price;

        if (!is_null($variant->price)) {
            $final_price = $variant->price;
        }

        return $final_price;
    }
}
