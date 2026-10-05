<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\BalanzaHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Helpers\VenderSearchHelper;
use App\Http\Controllers\Helpers\article\ArticlePricesHelper;
use App\Http\Controllers\Helpers\article\ArticleVariantBarCodeHelper;
use App\Models\Article;
use App\Models\ArticleVariant;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class VenderController extends Controller
{
    /**
     * Resuelve un codigo de barras escaneado (Vender y consultora de precios) a un articulo y, si el
     * codigo es de una VARIANTE, tambien a esa variante.
     *
     * Contrato C2 de la mision "codigo-de-barras-de-variantes": las claves de siempre de la
     * respuesta (`article`, `variant_id`, `variant`, `has_variants`, `variants`, `from_balanza`,
     * `from_balanza_plu`, `price_vender`, `amount`) no cambian de forma ni de significado, porque
     * las leen ArticleBarCode.vue y la consultora de precios (BuscadorInput.vue). Se suma
     * `variant_row`, que SOLO viene cuando el codigo fue de una variante: la misma fila que arma la
     * busqueda por nombre (`VenderSearchHelper::build_row`), o sea el formato con el que una
     * variante entra al remito. Un cliente viejo la ignora y sigue como siempre.
     *
     * Desde la misión balanzas-configurables (3/10/2026) se suman, solo en los tickets de balanza:
     * `balanza_id` (ticket leído por una balanza del ABM, de importe o de peso) y
     * `balanza_sin_articulo` + `balanza_nombre` (la balanza existe pero su artículo no sirve). Un
     * cliente viejo también las ignora. La lectura de tickets corre después de esa búsqueda, y solo
     * si no encontró ni variante ni artículo.
     *
     * La variante se busca primero (ver `ArticleVariantBarCodeHelper::find_available_by_code`):
     * solo con la extension `article_variants`, solo entre los articulos del duenio y solo entre las
     * disponibles. Si ninguna matchea, sigue la cadena de articulo de siempre
     * (`num` / `provider_code` / `bar_code` segun las extensiones).
     *
     * Mision "selector-de-variantes-con-precio-propio" (3/10/2026), contrato C2: cuando el codigo es
     * el de un ARTICULO con variantes disponibles (`has_variants: true`), cada elemento de `variants[]`
     * trae las claves de siempre (`variant_id`, `variant_description`, `final_price`, `bar_code`,
     * `images`, `addresses`, `oculta`), que no cambian de nombre, de forma ni de significado, y una
     * clave NUEVA: `precios_por_metodo_pago`, el desglose por metodo de pago de la Capa 3
     * (`ArticlePricesHelper::calcular_precios_por_metodo_pago_con_tarjeta_incluida`) calculado sobre el
     * `final_price` DE ESA VARIANTE (su precio propio o, si no tiene, el del articulo), o `null` si el
     * comercio no tiene `precio_base_incluye_tarjeta`. Es aditiva y opcional: un cliente viejo la
     * ignora y sigue como siempre, y el selector de la SPA la usa solo si llega (si no, conserva la del
     * articulo, que es lo que hacia antes). Existe para que el item que arma el selector sea coherente:
     * su `final_price` es el de la variante y el `price` absoluto de cada metodo tiene que serlo tambien.
     *
     * @param string $code Codigo escaneado.
     * @return \Illuminate\Http\JsonResponse
     */
    function search_bar_code($code) {

        $inicio = microtime(true);
        Log::info('Incia search_bar_code con codigo: '.$code);

        // Duenio de los articulos: toda la busqueda (variantes y articulos) se acota a el.
        $user_id = $this->userId();

        $article = Article::where('user_id', $user_id);

        // Si hay que consultar articulos. Pasa a false cuando el codigo, por su forma, no puede ser el
        // de ningun articulo en el modo de escaneo del comercio (ver la rama de numero interno).
        $buscar_articulo = true;

        // Id de la variante encontrada por codigo de barra (caso 1: codigo de variante)
        $variant_id = null;

        // Caso 1: el codigo escaneado es de una variante disponible del comercio (su bar_code o, con
        // numero interno, '0' + id). Se identifica ANTES que cualquier articulo, porque el codigo de
        // una variante manda sobre el de los articulos. El guardado de la variante impide repetir un
        // codigo de articulo existente, pero NO al reves (un articulo nuevo o importado puede traer
        // el codigo de una variante): en ese caso gana la variante.
        $variant = ArticleVariantBarCodeHelper::find_available_by_code($code, $user_id);

        if (!is_null($variant)) {

            $variant_id = $variant->id;
            $article = $article->where('id', $variant->article_id);
        } else if (UserHelper::hasExtencion('codigos_de_barra_basados_en_numero_interno')) {

            // Con la extension de numero interno el codigo de un articulo es su `num`.
            // Ojo: antes, un codigo que empezaba con '0' y no era de ninguna variante entraba por la
            // rama de la variante y dejaba esta consulta SIN ningun filtro: devolvia un articulo
            // cualquiera del duenio. No volver a mezclar las dos ramas: "no es una variante" tiene
            // que seguir siempre por la cadena de articulo.
            //
            // Pero NO se compara `num = $code` a secas: `num` es un entero y MySQL castea el texto, asi
            // que '0345' o '345abc' matchearian el articulo 345, que no tiene nada que ver con lo
            // escaneado. Dos reglas:
            //  - Un codigo que empieza con '0' y no resolvio como variante (oculta, borrada,
            //    inexistente) es "no encontrado": en este modo un 0 inicial siempre fue de una
            //    variante ('0' + id), nunca de un articulo (no hay `num` con cero de relleno).
            //  - Para el resto, se busca por `num` solo si el codigo es un entero canonico (solo
            //    digitos, sin ceros de relleno), comparado como entero.
            $internal_number = substr($code, 0, 1) === '0'
                ? null
                : ArticleVariantBarCodeHelper::canonical_integer($code);

            if (is_null($internal_number)) {
                $buscar_articulo = false;
            } else {
                $article = $article->where('num', $internal_number);
            }
        } else if (UserHelper::hasExtencion('codigo_proveedor_en_vender')) {

            $article = $article->where('provider_code', $code);
        } else {

            $article = $article->where('bar_code', $code);
        }

        // withAllSinAcopio: las mismas 27 relaciones menos sales_with_deliveries_in_acopio, que es la
        // cara del paquete (join article_sale/sales por en_acopio) y que ninguna de las dos pantallas
        // que consumen este endpoint lee. Ver el docblock del scope en App\Models\Article.
        // Sin consulta cuando el codigo no puede ser de un articulo: no hay nada que traer.
        $article = $buscar_articulo
                    ? $article->withAllSinAcopio()->first()
                    : null;

        // Si la variante existe pero su articulo no vino (no deberia pasar: la variante ya se acoto
        // a articulos del duenio), no se devuelve una variante huerfana: se sigue como "no encontrado".
        if (!$article) {
            $variant = null;
            $variant_id = null;
        }


        /*
         * Tickets de balanza (misión balanzas-configurables, 3/10/2026). Solo si la búsqueda de
         * arriba NO encontró nada, ni variante ni artículo (con la variante sin artículo, arriba ya
         * se anuló todo): una variante o un artículo cuyo código es exactamente el del ticket le
         * gana a cualquier balanza, como siempre.
         *
         * Qué lectura se intenta lo decide la configuración del dueño (`users.tickets_de_balanza`),
         * NO las extensiones `balanza_bar_code` / `plu_balanza_bar_code`, que ya no se leen (sus
         * filas siguen en la base: ver el comando balanzas:migrar-desde-extensiones). Las dos
         * lecturas son excluyentes. La lógica entera está en BalanzaHelper; acá solo se elige la
         * respuesta.
         */
        if (!$article) {

            $modo_balanza = UserHelper::modo_tickets_de_balanza();

            if ($modo_balanza === BalanzaHelper::MODO_BALANZAS) {

                $ticket = BalanzaHelper::leer_ticket_por_balanzas($code, $user_id);

                if (!is_null($ticket)) {

                    // La balanza existe pero su artículo no sirve (sin asignar, borrado o de otro
                    // dueño): VENDER avisa qué balanza revisar en vez de "no se encontró artículo".
                    if (is_null($ticket['article'])) {

                        $this->fin($code, $inicio, null);

                        return response()->json([
                            'article'               => null,
                            'has_variants'          => false,
                            'balanza_sin_articulo'  => true,
                            'balanza_nombre'        => BalanzaHelper::nombre_para_mostrar($ticket['balanza']),
                        ], 200);
                    }

                    $this->fin($code, $inicio, $ticket['article']);

                    // Peso: las MISMAS claves que el PLU, así la SPA lo agrega por el mismo camino
                    // (suma la cantidad si el artículo ya está) y una SPA vieja también lo entiende.
                    if ($ticket['tipo_dato'] === BalanzaHelper::TIPO_PESO) {

                        return response()->json([
                            'from_balanza_plu'  => true,
                            'article'           => $ticket['article'],
                            'amount'            => $ticket['amount'],
                            'balanza_id'        => $ticket['balanza']->id,
                        ], 200);
                    }

                    // Importe: las claves de siempre (from_balanza + price_vender) más balanza_id.
                    return response()->json([
                        'from_balanza'  => true,
                        'article'       => $ticket['article'],
                        'price_vender'  => $ticket['price_vender'],
                        'balanza_id'    => $ticket['balanza']->id,
                    ], 200);
                }

            } else if ($modo_balanza === BalanzaHelper::MODO_PLU) {

                // La lectura PLU de siempre, idéntica (La Martina).
                $res = BalanzaHelper::leer_ticket_por_plu($code, $user_id);

                if (!is_null($res['article'])) {

                    $this->fin($code, $inicio, $res['article']);
                    return response()->json(['from_balanza_plu' => true, 'article' => $res['article'], 'amount' => $res['amount']], 200);
                }
            }
        }

        $this->fin($code, $inicio, $article);

        // Capa 3 (Prompt 263, hotfix Prompt 313): `precios_por_metodo_pago` es un accessor del
        // modelo Article, ya viene incluido en el JSON de $article sin necesidad de asignarlo aca.

        // Caso 1: se encontro una variante puntual por codigo de barra -> devolverla directo
        // para que el front la agregue sin pasar por el selector de variantes.
        if (!is_null($variant)) {

            // Fila lista para el remito: la misma que devuelve la busqueda por nombre para esa
            // variante (`is_variant`, `variant_id`, `variant_description`, `final_price` con el
            // precio propio si lo tiene, `images`, `addresses`, `article` anidado, etc.).
            // Se arma sobre una COPIA de la variante, no sobre `$variant`: `build_row` necesita
            // `$variant->article` y se lo setea con el articulo que ya se trajo (con sus imagenes
            // cargadas) para ahorrar una consulta por articulo e imagenes. Si se lo seteara sobre
            // `$variant` -- que se devuelve como `variant` -- el JSON de esa clave pasaria a llevar
            // el articulo completo anidado y cambiaria de forma: `variant` tiene que seguir siendo
            // el modelo pelado que leen ArticleBarCode.vue y la consultora de precios.
            $variant_for_row = clone $variant;
            $variant_for_row->setRelation('article', $article);

            $variant_row = VenderSearchHelper::build_row($article, $variant_for_row);

            return response()->json([
                'article'     => $article,
                'variant_id'  => $variant_id,
                'variant'     => $variant,
                'variant_row' => $variant_row,
            ], 200);
        }

        // A partir de aca el codigo matcheo un articulo (no una variante puntual).
        // Hay que distinguir si ese articulo tiene variantes disponibles (oculta = false) o no,
        // porque el front necesita saber si debe abrir el selector de variantes (caso 3)
        // o agregar el articulo directo (caso 2).
        if ($article && UserHelper::hasExtencion('article_variants')) {

            // Solo las variantes disponibles (no ocultas) se ofrecen para vender
            $available_variants = ArticleVariant::where('article_id', $article->id)
                                        ->where('oculta', false)
                                        ->with('addresses')
                                        ->get();

            if ($available_variants->count() > 0) {

                // Caso 3: articulo con variantes disponibles -> el front debe abrir el selector.
                // Shapeamos cada variante igual que las filas "is_variant" de search_nombre.
                $variants_shaped = collect();

                foreach ($available_variants as $available_variant) {

                    // El precio de la variante se calcula UNA sola vez: es el que va en `final_price`
                    // y el mismo sobre el que se arma el desglose por metodo de pago. Calcularlos por
                    // separado dejaria abierta la puerta a que los dos digan cosas distintas.
                    $variant_final_price = VenderSearchHelper::get_variant_price($available_variant);

                    $variants_shaped->push((object)[
                        'variant_id'            => $available_variant->id,
                        'variant_description'   => $available_variant->variant_description,
                        'final_price'           => $variant_final_price,
                        // Clave NUEVA (contrato C2, aditiva y opcional): el desglose de la Capa 3 sobre
                        // el precio de ESTA variante, igual que lo arman `build_row` (busqueda por
                        // nombre / escaneo de una variante). `null` sin `precio_base_incluye_tarjeta`.
                        // El helper cachea la configuracion del comercio por request: no suma
                        // consultas por variante.
                        'precios_por_metodo_pago' => ArticlePricesHelper::calcular_precios_por_metodo_pago_con_tarjeta_incluida($variant_final_price, $user_id),
                        'bar_code'              => $available_variant->bar_code,
                        'images'                => VenderSearchHelper::get_variant_images($available_variant),
                        'addresses'             => $available_variant->addresses,
                        'oculta'                => $available_variant->oculta,
                    ]);
                }

                return response()->json([
                    'article'       => $article,
                    'has_variants'  => true,
                    'variants'      => array_values($variants_shaped->toArray()),
                ], 200);
            }
        }

        // Caso 2: articulo sin variantes disponibles -> agregar directo (comportamiento previo)
        return response()->json(['article' => $article, 'has_variants' => false], 200);
    }

    function fin($code, $inicio, $article) {

        $fin = microtime(true);
        $duracion = $fin - $inicio;

        Log::info('Duración total para '.$code.': ' . number_format($duracion, 3) . ' segundos');
        if ($article) {
            Log::info('Articulo encontrado para el codigo '.$code.': '.$article->name.'. id: '.$article->id);
        } else {
            Log::info('NO se encontro Articulo para el codigo '.$code);
        }
    }

    /*
     * `check_balanza()` y `check_balanza_plu()` ya no viven acá (misión balanzas-configurables,
     * 3/10/2026):
     *
     *   - check_balanza() se BORRÓ: le imputaba todo ticket '22' a un artículo con id hardcodeado
     *     (60 en local, 6346 en el resto), sin mirar de qué comercio era. Lo reemplazan las
     *     balanzas del ABM (BalanzaHelper::leer_ticket_por_balanzas()), y el comando
     *     balanzas:migrar-desde-extensiones le crea a Panchito la balanza '22' -> ese artículo.
     *   - check_balanza_plu() se MOVIÓ sin cambios a BalanzaHelper::leer_ticket_por_plu().
     */

    /**
     * Busqueda de articulos del modulo de Vender (search modal). Este endpoint sigue vivo como
     * respaldo del frontend viejo (Prompt 04, grupo 179: el buscador general ahora soporta el
     * mismo comportamiento via `contexto`); su respuesta y su comportamiento NO cambian, solo se
     * movio la logica de negocio (exclusion de insumos, codigo de barras exacto y expansion de
     * variantes) a `VenderSearchHelper` para que `SearchController::globalSearch` pueda reusarla.
     *
     * @param Request $request
     * @param int|bool $from_provider_order_or_recipe Si viene de un pedido a proveedor o receta
     *        (no excluye insumos, y habilita la busqueda exacta por codigo de barras aunque el
     *        cliente no tenga la extension `search_bar_code_en_vender`).
     * @return \Illuminate\Http\JsonResponse Forma plana (current_page/data/per_page/total/last_page
     *         en la raiz), distinta de la de `globalSearch`.
     */
    function search_nombre(Request $request, $from_provider_order_or_recipe = 0) {

        // Palabras del criterio de busqueda, tal como las separa el helper.
        $keywords = explode(' ', trim($request->query_value));

        // Extensiones que habilitan busqueda por descripcion y por codigo de barras (parcial).
        $search_descripcion_en_vender = UserHelper::hasExtencion('search_descripcion_en_vender');
        $search_bar_code_en_vender = UserHelper::hasExtencion('search_bar_code_en_vender');

        // Filtros fijos propios de Vender (categoria y opcion de stock).
        $category_id = $request->category_id;
        $stock_option = $request->stock_option;

        // Paginado manual (la expansion de variantes cambia la cantidad de filas).
        $per_page = 50;
        $current_page = LengthAwarePaginator::resolveCurrentPage();

        // Contexto equivalente para el helper: 'provider_order' cubre tanto pedido a proveedor
        // como receta (ambos casos comparten exactamente la misma logica de exclusion de insumos
        // y de codigo de barras que hoy tenia este flag unico).
        $contexto = $from_provider_order_or_recipe ? 'provider_order' : 'vender';

        // 1. Buscar todos los artículos cuyo name o provider_code coincidan con alguna palabra
        $articles = Article::where('status', 'active')
                        ->where('user_id', $this->userId());

        // Exclusion de insumos, delegada en el helper (salvo pedido a proveedor / receta).
        $articles = VenderSearchHelper::apply_conditions($articles, $request->query_value, $contexto);

        // Callback de coincidencia exacta por codigo de barras (articulo y variantes), delegado en
        // el helper para reusar la misma logica que usa el buscador general con contexto Vender.
        $bar_code_condition = VenderSearchHelper::bar_code_condition_callback();

        // Extension de `name` con la descripcion de las variantes (null sin la extension
        // `article_variants`). Mismo callback que usa el buscador general con contexto Vender, para
        // que las dos rutas devuelvan lo mismo.
        $variant_condition = VenderSearchHelper::variant_description_condition_callback();

        $articles->where(function ($query_builder) use ($keywords, $from_provider_order_or_recipe, $search_descripcion_en_vender, $search_bar_code_en_vender, $bar_code_condition, $variant_condition) {
                            if (count($keywords) === 1) {
                                $keyword = $keywords[0];

                                $query_builder->where(function ($q) use ($keyword, $keywords, $from_provider_order_or_recipe, $search_descripcion_en_vender, $search_bar_code_en_vender, $bar_code_condition, $variant_condition) {
                                    $q->where('name', 'LIKE', "%$keyword%")
                                      ->orWhere('provider_code', 'LIKE', "%$keyword%");

                                      if ($search_descripcion_en_vender) {

                                        $q->orWhereRaw('LOWER(descripcion) LIKE ?', ['%' . mb_strtolower($keyword) . '%']);
                                      }

                                      Log::info('Buscando por descripcion: '.$keyword);

                                    // Búsqueda exacta por código de barra (artículo o variante) si la extensión está habilitada
                                    // o si el flujo proviene de pedido a proveedor / receta.
                                    if ($search_bar_code_en_vender || $from_provider_order_or_recipe) {
                                        if ($from_provider_order_or_recipe) {
                                            Log::info('from_provider_order_or_recipe '.$keyword);
                                        }

                                        $bar_code_condition($q, $keywords);
                                    }

                                    // Extension de `name` con las variantes (ultima alternativa del OR).
                                    if ($variant_condition) {
                                        $variant_condition($q, $keyword);
                                    }
                                });
                            } else {
                                foreach ($keywords as $keyword) {
                                    $query_builder->where(function ($q) use ($keyword, $search_descripcion_en_vender, $search_bar_code_en_vender, $variant_condition) {
                                        $q->where('name', 'LIKE', "%$keyword%")
                                            ->orWhere('provider_code', 'LIKE', "%$keyword%");

                                        if ($search_descripcion_en_vender) {

                                          $q->orWhere('descripcion', 'LIKE', "%$keyword%");
                                        }

                                        if ($search_bar_code_en_vender) {
                                            $q->orWhere('bar_code', 'LIKE', "%$keyword%");
                                            $q->orWhereHas('article_variants', function ($variant_query) use ($keyword) {
                                                $variant_query->where('bar_code', 'LIKE', "%$keyword%");
                                            });
                                        }

                                        // Extension de `name` con las variantes (ultima alternativa del OR).
                                        if ($variant_condition) {
                                            $variant_condition($q, $keyword);
                                        }
                                    });
                                }
                            }
                        });

        if ($category_id) {
            Log::info('category_id');
            $articles->where('category_id', $category_id);
        }

        if ($stock_option) {

            if ($stock_option == 'con_stock') {
                Log::info('stock > 0');
                $articles->where('stock', '>', 0);
            } else if ($stock_option == 'hayan_tenido_stock') {
                Log::info('stock not null');
                $articles->whereNotNull('stock');
            }
        }

        // Paginado real (2 fases), en vez de traer TODOS los articulos que matchean con las 27
        // relaciones de withAllSinAcopio() antes de paginar en PHP.
        //
        // Fase 1: consulta LIVIANA (mismo WHERE de arriba, sin ninguna relacion pesada) solo para
        // decidir que pares (articulo, variante) son el resultado y en que orden -- lo unico que
        // hace falta para saber el total real y cual pagina le toca a cada uno.
        $articles_query = $articles->select('id', 'name', 'provider_code', 'bar_code');

        if (UserHelper::hasExtencion('article_variants')) {
            $articles_query->with(['article_variants' => function ($variant_query) {
                $variant_query->select('id', 'article_id', 'bar_code', 'oculta', 'variant_description');
            }]);
        }

        $light_articles = $articles_query->get();

        Log::info(count($light_articles). ' articulos');

        $descriptors = VenderSearchHelper::match_descriptors($light_articles, $request->query_value);

        $page_descriptors = $descriptors->forPage($current_page, $per_page)->values();

        // Fase 2: recien aca se cargan las 27 relaciones de withAllSinAcopio(), y SOLO para los
        // articulos de la pagina pedida (como mucho $per_page, nunca para los que matchean pero no
        // se van a mostrar).
        $full_articles = Article::whereIn('id', $page_descriptors->pluck('article_id')->unique()->values())
                            ->withAllSinAcopio()
                            ->get()
                            ->keyBy('id');

        // El orden final es el de los descriptores (Fase 1), no el de whereIn (Fase 2, sin garantia
        // de orden): se arma buscando cada articulo completo por id.
        $results = $page_descriptors->map(function ($descriptor) use ($full_articles) {
                        $article = $full_articles->get($descriptor->article_id);

                        if (is_null($article)) {
                            return null;
                        }

                        $variant = is_null($descriptor->variant_id)
                                    ? null
                                    : optional($article->article_variants)->firstWhere('id', $descriptor->variant_id);

                        return VenderSearchHelper::build_row($article, $variant);
                    })
                    ->filter()
                    ->values();

        // Paginar manualmente, con el total real (Fase 1) y los resultados ya armados (Fase 2).
        $paginated = new LengthAwarePaginator(
            $results,
            $descriptors->count(),
            $per_page,
            $current_page
        );

        return response()->json([
            'current_page' => $paginated->currentPage(),
            'data' => array_values($paginated->items()), // 👈 forzar índices numéricos planos
            'per_page' => $paginated->perPage(),
            'total' => $paginated->total(),
            'last_page' => $paginated->lastPage(),
        ], 200);

    }
}
