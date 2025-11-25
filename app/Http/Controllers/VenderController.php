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
        
        $variant_id = null; 
        $variant = null; 

        if (UserHelper::hasExtencion('codigos_de_barra_basados_en_numero_interno')) {

            if (substr($code, 0, 1) == '0') {
                
                // El codigo es el id de una variante
                $variant = ArticleVariant::find(substr($code, 1));

                if (!is_null($variant)) {
                    
                    $variant_id = $variant->id; 
                    $variant = $variant; 

                    $article_id = $variant->article_id;
                }
                $article = $article->where('id', $article_id);
            } else {

                $article = $article->where('num', $code);
            }
        } else if (UserHelper::hasExtencion('codigo_proveedor_en_vender')) {
            $article = $article->where('provider_code', $code);
        } else {

            $article = $article->where('bar_code', $code);

            
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

        $this->fin($code, $inicio, $article);

        return response()->json(['article' => $article, 'variant_id' => $variant_id, 'variant' => $variant], 200);
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
        if (env('APP_ENV') == 'local') {
            $default_article_id = 60;
        } else {

            // Id de la carniceria
            $default_article_id = 6346;
        }

        if ($prefix == '22') {

            $last_6_digits = substr($barcode, -8);
            $amount_str = substr($last_6_digits, 0, 7);

            $price_vender = intval($amount_str);

            $article = Article::find($default_article_id);
        }

        return [
            'article'           => $article,
            'price_vender'      => $price_vender,
        ];

    }

    function search_nombre(Request $request) {

        $keywords = explode(' ', trim($request->query_value));

        $category_id = $request->category_id;

        $per_page = 50;
        $current_page = LengthAwarePaginator::resolveCurrentPage();

        $results = collect();
        // 1. Buscar todos los artículos cuyo name o provider_code coincidan con alguna palabra
        $articles = Article::where('status', 'active')
                        ->where(function ($query_builder) use ($keywords) {
                            if (count($keywords) === 1) {
                                $keyword = $keywords[0];
                                $query_builder->where(function ($q) use ($keyword) {
                                    Log::info($keyword);
                                    $q->where('name', 'LIKE', "%$keyword%")
                                      ->orWhere('provider_code', 'LIKE', "%$keyword%");
                                      // ->orWhere('descripcion', 'LIKE', "%$keyword%");
                                });
                            } else {
                                foreach ($keywords as $keyword) {
                                    $query_builder->where(function ($q) use ($keyword) {
                                        $q->where('name', 'LIKE', "%$keyword%");
                                          // ->orWhere('descripcion', 'LIKE', "%$keyword%");
                                    });
                                }
                            }
                        })
                    ->with(['article_variants', 'images', 'price_types', 'addresses', 'price_type_monedas', 'article_price_ranges']);

        if ($category_id) {
            Log::info('category_id');
            $articles->where('category_id', $category_id);
        }

        $articles = $articles->get();


        foreach ($articles as $article) {

            // Detectar qué palabras de la búsqueda coincidieron con el nombre o código del artículo
            $matched_keywords = collect($keywords)->filter(function ($word) use ($article) {
                return str_contains(
                                   mb_strtolower($article->name ?? '', 'UTF-8'),
                                   mb_strtolower($word, 'UTF-8')
                               ) ||
                               str_contains(
                                   mb_strtolower($article->provider_code ?? '', 'UTF-8'),
                                   mb_strtolower($word, 'UTF-8')
                               );
            })->values();

            // Palabras restantes para buscar dentro de variant_description
            $remaining_keywords = array_diff($keywords, $matched_keywords->toArray());

            // Log::info('remaining_keywords:');
            // Log::info($remaining_keywords);

            // Si el artículo tiene variantes
            if ($article->article_variants->count() > 0) {

                Log::info('Buscando variantes');

                // Filtrar variantes que coincidan con todas las palabras restantes
                $matching_variants = $article->article_variants->filter(function ($variant) use ($remaining_keywords) {
                    foreach ($remaining_keywords as $word) {
                        if (!str_contains(
                                mb_strtolower($variant->variant_description ?? '', 'UTF-8'),
                                mb_strtolower($word, 'UTF-8')
                            )) {
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
