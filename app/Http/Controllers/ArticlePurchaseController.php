<?php

namespace App\Http\Controllers;

use App\Models\ArticlePurchase;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class ArticlePurchaseController extends Controller
{
    function index(Request $request) {

        $client_id = $request->client_id;
        $provider_id = $request->provider_id;
        $category_id = $request->category_id;
        $cantidad_resultados = $request->cantidad_resultados;
        $orden = $request->orden;

        $mes_inicio = $request->mes_inicio;
        $mes_inicio = Carbon::createFromFormat('Y-m', $mes_inicio)->startOfMonth();

        $mes_fin = $request->mes_fin;
        $mes_fin = Carbon::createFromFormat('Y-m', $mes_fin)->endOfMonth();

        Log::info('mes_inicio: '.$mes_inicio->format('d/m/y'));
        Log::info('mes_fin: '.$mes_fin->format('d/m/y'));

        $purchases = ArticlePurchase::whereBetween('created_at', [$mes_inicio, $mes_fin])
                                ->with('article.provider');

        if (!is_null($provider_id)) {
            $purchases = $purchases->whereHas('article', function($query) use ($provider_id) {
                $query->where('provider_id', $provider_id);
            });
        }

        if (!is_null($client_id)) {
            Log::info('Filtrando por client_id: '.$client_id);
            $purchases = $purchases->where('client_id', $client_id);
        }

        if (!is_null($category_id)) {
            $purchases = $purchases->where('category_id', $category_id);
        }

        $purchases = $purchases->get();

        $purchases = $this->agrupar_articulos($purchases, $orden, $cantidad_resultados);

        $categories = $this->get_categories($purchases, $orden);

        $providers = $this->get_providers($purchases, $orden);
        
        return response()->json([
            'models' => $purchases, 
            'categories' => $categories, 
            'providers' => $providers
        ], 200);
    }

    function agrupar_articulos($purchases, $orden, $cantidad_resultados) {

        $agrupados = [];

        foreach ($purchases as $purchase) {

            $article_id = $purchase->article_id;

            if (isset($agrupados[$article_id])) {

                $agrupados[$article_id]['unidades_vendidas'] += $purchase->amount;
                $agrupados[$article_id]['price'] += $purchase->price * $purchase->amount;
                $agrupados[$article_id]['cost'] += $purchase->cost * $purchase->amount;

                if ($article_id == 1) {
                    Log::info('Se SUMA a prensa con price = '.$purchase->price);
                }
           
            } else {

                $agrupados[$article_id] = [
                    'provider_code'     => $purchase->article->provider_code, 
                    'article_name'      => $purchase->article->name, 
                    'category'          => $this->get_relation($purchase, 'category'), 
                    'provider'          => $this->get_relation($purchase, 'provider'), 
                    'article'           => $purchase->article, 
                    'unidades_vendidas'            => $purchase->amount,
                    'price'             => $purchase->price * $purchase->amount,
                    'cost'              => $purchase->cost * $purchase->amount,
                ];

                if ($article_id == 1) {
                    Log::info('Se inico prensa con price = '.$purchase->price);
                }
            }
        }

        foreach ($agrupados as $article_id => $agrupado) {

            $agrupados[$article_id]['beneficio'] = (float)$agrupados[$article_id]['price'] - (float)$agrupados[$article_id]['cost'];
        }

        $agrupados = array_values($agrupados);

        if ($orden == 'mayor-menor') {

            usort($agrupados, function($a, $b) { 
                return $b['unidades_vendidas'] - $a['unidades_vendidas']; 
            });

        } else if ($orden == 'menor-mayor') {

            usort($agrupados, function($a, $b) { 
                return $a['unidades_vendidas'] - $b['unidades_vendidas']; 
            });

        }

        $agrupados = array_slice($agrupados, 0, $cantidad_resultados-1);

        return $agrupados;
    }

    function get_relation($purchase, $relation_name) {

        if (!is_null($purchase->article->{$relation_name})) {

            return $purchase->article->{$relation_name}->toArray();
        }

        return null;
    }

    function get_categories($purchases, $orden) {

        $categories = [];

        foreach ($purchases as $article_pruchase) {

            if (!is_null($article_pruchase['category'])) {

                $category_id = $article_pruchase['category']['id'];
                
                if (isset($categories[$category_id])) {

                    $categories[$category_id]['unidades_vendidas'] += $article_pruchase['unidades_vendidas'];
                    $categories[$category_id]['price'] += $article_pruchase['price'];
                    $categories[$category_id]['cost'] += $article_pruchase['cost'];
               
                } else {

                    $categories[$category_id] = [
                        'category_name'         => $article_pruchase['category']['name'], 
                        'unidades_vendidas'                => $article_pruchase['unidades_vendidas'],
                        'price'                 => $article_pruchase['price'],
                        'cost'                  => $article_pruchase['cost'],
                    ];
                }
            }

        }

        foreach ($categories as $category_id => $categoria) {

            $categories[$category_id]['beneficio'] = (float)$categories[$category_id]['price'] - (float)$categories[$category_id]['cost'];
        }

        $categories = array_values($categories);

        if ($orden == 'mayor-menor') {

            usort($categories, function($a, $b) { 
                return $b['unidades_vendidas'] - $a['unidades_vendidas']; 
            });

        } else if ($orden == 'menor-mayor') {

            usort($categories, function($a, $b) { 
                return $a['unidades_vendidas'] - $b['unidades_vendidas']; 
            });

        }

        return $categories;
    }

    function get_providers($purchases, $orden) {

        $providers = [];

        foreach ($purchases as $article_pruchase) {

            if (!is_null($article_pruchase['provider'])) {

                $provider_id = $article_pruchase['provider']['id'];
                
                if (isset($providers[$provider_id])) {

                    $providers[$provider_id]['unidades_vendidas'] += $article_pruchase['unidades_vendidas'];
                    $providers[$provider_id]['price'] += $article_pruchase['price'];
                    $providers[$provider_id]['cost'] += $article_pruchase['cost'];
               
                } else {

                    $providers[$provider_id] = [
                        'provider_name'         => $article_pruchase['provider']['name'], 
                        'unidades_vendidas'     => $article_pruchase['unidades_vendidas'],
                        'price'                 => $article_pruchase['price'],
                        'cost'                  => $article_pruchase['cost'],
                    ];
                }
            }

        }

        foreach ($providers as $provider_id => $provider) {

            $providers[$provider_id]['beneficio'] = (float)$providers[$provider_id]['price'] - (float)$providers[$provider_id]['cost'];
        }

        $providers = array_values($providers);

        if ($orden == 'mayor-menor') {

            usort($providers, function($a, $b) { 
                return $b['unidades_vendidas'] - $a['unidades_vendidas']; 
            });

        } else if ($orden == 'menor-mayor') {

            usort($providers, function($a, $b) { 
                return $a['unidades_vendidas'] - $b['unidades_vendidas']; 
            });

        }

        return $providers;
    }
}
