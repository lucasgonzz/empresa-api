<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\UserHelper;
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
                        'final_price'           => $this->get_variant_price($available_variant),
                        'bar_code'              => $available_variant->bar_code,
                        'images'                => $this->get_variant_images($available_variant),
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

    function search_nombre(Request $request, $from_provider_order_or_recipe = 0) {

        $keywords = explode(' ', trim($request->query_value));

        $search_descripcion_en_vender = UserHelper::hasExtencion('search_descripcion_en_vender');
        $search_bar_code_en_vender = UserHelper::hasExtencion('search_bar_code_en_vender');

        $category_id = $request->category_id;
        $stock_option = $request->stock_option;

        $per_page = 50;
        $current_page = LengthAwarePaginator::resolveCurrentPage();

        $results = collect();
        // 1. Buscar todos los artículos cuyo name o provider_code coincidan con alguna palabra
        $articles = Article::where('status', 'active')
                        ->where('user_id', $this->userId());

        if (!$from_provider_order_or_recipe) {
            $articles->where(function($q) {
                            $q->where('es_insumo', 0)
                                ->orWhereNull('es_insumo');
                        });
        } 

        $articles->where(function ($query_builder) use ($keywords, $from_provider_order_or_recipe, $search_descripcion_en_vender, $search_bar_code_en_vender) {
                            if (count($keywords) === 1) {
                                $keyword = $keywords[0];

                                $query_builder->where(function ($q) use ($keyword, $from_provider_order_or_recipe, $search_descripcion_en_vender, $search_bar_code_en_vender) {
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

                                        $q->orWhere('bar_code', $keyword);
                                        $q->orWhereHas('article_variants', function ($variant_query) use ($keyword) {
                                            $variant_query->where('bar_code', $keyword);
                                        });
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

        // Fix duplicación (prompt 520): antes $results arrancaba como $articles y despues,
        // si el usuario tiene la extensión article_variants, se le pusheaban ADEMÁS las filas
        // is_variant de cada artículo con variantes -> el padre quedaba duplicado junto a sus
        // variantes. Ahora $results arranca vacío y cada artículo se agrega una sola vez,
        // ya sea como fila normal (sin variantes disponibles) o como fila(s) is_variant.
        if (!UserHelper::hasExtencion('article_variants')) {

            // Sin la extensión, el comportamiento es el de siempre: todos los artículos matcheados.
            $results = $articles;
        } else {

            foreach ($articles as $article) {

                // Detectar qué palabras de la búsqueda coincidieron con el nombre, código o barcode del artículo/variante
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

                // Palabras restantes para buscar dentro de variant_description
                $remaining_keywords = array_diff($keywords, $matched_keywords->toArray());

                // Solo se ofrecen para vender las variantes disponibles (oculta = false)
                $available_variants = $article->article_variants->filter(function ($variant) {
                    return !$variant->oculta;
                });

                // Si el artículo tiene variantes disponibles
                if ($available_variants->count() > 0) {

                    Log::info('Buscando variantes');

                    // Coincidencia exacta por barcode de variante: devolver solo esa variante
                    if ($search_bar_code_en_vender && count($keywords) === 1) {
                        $keyword = $keywords[0];
                        $matching_variants_by_bar_code = $available_variants->filter(function ($variant) use ($keyword) {
                            return ($variant->bar_code ?? '') === $keyword;
                        });

                        if ($matching_variants_by_bar_code->count() > 0) {
                            foreach ($matching_variants_by_bar_code as $variant) {
                                $results->push((object)[
                                    'is_variant'            => true,
                                    'id'                    => $variant->article->id,
                                    'variant_id'            => $variant->id,
                                    'variant_description'   => $variant->variant_description,
                                    'final_price'           => $this->get_variant_price($variant),
                                    'price_types'           => $article->price_types,
                                    'bar_code'              => $variant->bar_code,
                                    'name'                  => $article->name. ' '.$variant->variant_description,
                                    'article'               => $article,
                                    'images'                => $this->get_variant_images($variant),
                                    'addresses'             => $variant->addresses,
                                ]);
                            }

                            continue;
                        }
                    }

                    // Filtrar variantes (disponibles) que coincidan con todas las palabras restantes
                    $matching_variants = $available_variants->filter(function ($variant) use ($remaining_keywords) {
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
                            $results->push((object)[
                                'is_variant'            => true,
                                'id'                    => $variant->article->id,
                                'variant_id'            => $variant->id,
                                'variant_description'   => $variant->variant_description,
                                'final_price'           => $this->get_variant_price($variant),
                                'price_types'           => $article->price_types,
                                'bar_code'              => $variant->bar_code,
                                'name'                  => $article->name. ' '.$variant->variant_description,
                                'article'               => $article,
                                'images'                => $this->get_variant_images($variant),
                                'addresses'             => $variant->addresses,
                            ]);
                        }
                    }

                } else {
                    // Si no tiene variantes, y al menos una keyword matcheó → agregar el artículo
                    if ($matched_keywords->isNotEmpty()) {
                        $article->is_variant = false;
                        $results->push($article);
                    }
                }
            }
        }

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




    function get_variant_images($variant) {
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

    function get_variant_price($variant) {

        $final_price = $variant->article->final_price;

        if (!is_null($variant->price)) {
            $final_price = $variant->price;
        }

        return $final_price;
    }
}
