<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Helpers\VenderSearchHelper;
use App\Models\Article;
use App\Models\ArticleVariant;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class VenderController extends Controller
{
    function search_bar_code($code) {

        $inicio = microtime(true);
        Log::info('Incia search_bar_code con codigo: '.$code);

        $article = Article::where('user_id', $this->userId());

        // Id de la variante encontrada por codigo de barra (caso 1: codigo de variante)
        $variant_id = null;
        // Instancia de la variante encontrada por codigo de barra (caso 1: codigo de variante)
        $variant = null;

        if (UserHelper::hasExtencion('codigos_de_barra_basados_en_numero_interno') && substr($code, 0, 1) == '0') {

            // El codigo es el id de una variante ('0' + id, contrato del prompt 518/519)
            $variant = ArticleVariant::find(substr($code, 1));

            if (!is_null($variant)) {

                $variant_id = $variant->id;
                $article_id = $variant->article_id;

                $article = $article->where('id', $article_id);
            }
        } else {

            // Caso 1 (alternativo): el codigo escaneado matchea directo el bar_code de una variante
            // (contrato definido en el prompt 518/519: article_variants.bar_code). Se revisa
            // independientemente de las extensiones de matcheo de articulo, porque el codigo de
            // una variante siempre se identifica primero como variante.
            $variant = ArticleVariant::where('bar_code', $code)->first();

            if (!is_null($variant)) {

                $variant_id = $variant->id;
                $article = $article->where('id', $variant->article_id);
            } else if (UserHelper::hasExtencion('codigos_de_barra_basados_en_numero_interno')) {

                $article = $article->where('num', $code);
            } else if (UserHelper::hasExtencion('codigo_proveedor_en_vender')) {

                $article = $article->where('provider_code', $code);
            } else {

                $article = $article->where('bar_code', $code);
            }
        }

        $article = $article->withAll()
                        ->first();


        if (
            !$article
            && UserHelper::hasExtencion('balanza_bar_code')
        ) {
            $res = $this->check_balanza($code);

            if (!is_null($res['article'])) {

                $this->fin($code, $inicio, $res['article']);
                return response()->json(['from_balanza' => true, 'article' => $res['article'], 'price_vender' => $res['price_vender']], 200);
            }
        }

        if (
            !$article
            && UserHelper::hasExtencion('plu_balanza_bar_code')
        ) {
            $res = $this->check_balanza_plu($code);

            if (!is_null($res['article'])) {

                $this->fin($code, $inicio, $res['article']);
                return response()->json(['from_balanza_plu' => true, 'article' => $res['article'], 'amount' => $res['amount']], 200);
            }
        }

        $this->fin($code, $inicio, $article);

        // Capa 3 (Prompt 263, hotfix Prompt 313): `precios_por_metodo_pago` es un accessor del
        // modelo Article, ya viene incluido en el JSON de $article sin necesidad de asignarlo aca.

        // Caso 1: se encontro una variante puntual por codigo de barra -> devolverla directo
        // para que el front la agregue sin pasar por el selector de variantes.
        if (!is_null($variant)) {
            return response()->json(['article' => $article, 'variant_id' => $variant_id, 'variant' => $variant], 200);
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
                    $variants_shaped->push((object)[
                        'variant_id'            => $available_variant->id,
                        'variant_description'   => $available_variant->variant_description,
                        'final_price'           => VenderSearchHelper::get_variant_price($available_variant),
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

    function check_balanza($barcode) {

        $prefix = substr($barcode, 0, 2);

        $article = null;
        $price_vender = null;

        // Esto lo guardaria en bbdd, ahora lo harckodeo para panchito
        if (config('app.APP_ENV') == 'local') {
            $default_article_id = 60;
        } else {

            // Id de la carniceria
            $default_article_id = 6346;
        }

        if ($prefix == '22') {

            $last_6_digits = substr($barcode, -8);
            $amount_str = substr($last_6_digits, 0, 7);

            $price_vender = intval($amount_str);

            $article = Article::where('id', $default_article_id)
                                ->withAll()
                                ->first();
        }

        return [
            'article'           => $article,
            'price_vender'      => $price_vender,
        ];
    }

    function check_balanza_plu($barcode) {

        if (mb_strlen($barcode) < 12) {
            return [
                'article'   => null,
            ];
        }


        // 2
        $tipo_balanza = mb_substr($barcode, 0, 2);

        // 5 (quita ceros iniciales)
        $plu = ltrim(mb_substr($barcode, 2, 5), '0');

        // 6 (quita ceros iniciales)
        $amount = ltrim(mb_substr($barcode, 7, 5), '0');

        // Si queda vacío (ej: "00000"), lo llevamos a 0
        $amount = $amount === '' ? 0 : (float) $amount;

        Log::info('tipo_balanza: '.$tipo_balanza);
        Log::info('plu: '.$plu);
        Log::info('amount: '.$amount);

        $article = Article::where('user_id', $this->userId())
                            ->where('plu', $plu)
                            ->withAll()
                            ->first();

        if ($article && $article->unidad_medida_id != 2) {
            $amount /= 1000;
        }

        return [
            'article'     => $article,
            'amount'      => $amount,
        ];
    }

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

        $articles->where(function ($query_builder) use ($keywords, $from_provider_order_or_recipe, $search_descripcion_en_vender, $search_bar_code_en_vender, $bar_code_condition) {
                            if (count($keywords) === 1) {
                                $keyword = $keywords[0];

                                $query_builder->where(function ($q) use ($keyword, $keywords, $from_provider_order_or_recipe, $search_descripcion_en_vender, $search_bar_code_en_vender, $bar_code_condition) {
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
                                });
                            } else {
                                foreach ($keywords as $keyword) {
                                    $query_builder->where(function ($q) use ($keyword, $search_descripcion_en_vender, $search_bar_code_en_vender) {
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
                                    });
                                }
                            }
                        })
                    ->withAll();
                    // ->with(['article_variants', 'images', 'price_types', 'addresses', 'price_type_monedas', 'article_price_ranges', 'provider']);

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

        $articles = $articles->get();

        Log::info(count($articles). ' articulos');

        // Expansion de variantes en filas propias, delegada en el helper (mismas claves y mismo
        // orden de casos que antes de este prompt).
        $results = VenderSearchHelper::expand_variants($articles, $request->query_value);

        // Paginar manualmente
        $paginated = new LengthAwarePaginator(
            $results->forPage($current_page, $per_page),
            $results->count(),
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
