<?php

namespace App\Http\Controllers;

use App\Models\ArticlePurchase;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class ArticlePurchaseController extends Controller
{
    /**
     * Reportes → Artículos: lo vendido en el período, agrupado por artículo, categoría y proveedor.
     *
     * 🔴 LOS TOTALES Y LOS GRÁFICOS SON DEL PERÍODO, NO DE LA LISTA (misión
     * reporte-articulos-totales-del-periodo, 10/10/2026). Hasta esta fecha las categorías y los
     * proveedores se armaban con la lista ya recortada a `cantidad_resultados`, y el SPA sumaba esa
     * misma lista para el "Total": el dueño leía como "lo vendido en el período" lo que eran solo
     * los 10 primeros. Ahora se agrupa todo el período, de ahí salen `totales`, `categories` y
     * `providers`, y recién al final se recorta `models`.
     *
     * 🔴 SOLO LO DEL DUEÑO. `article_purchases` no tiene `user_id`: el dueño sale de la venta. Sin
     * este filtro, en una base compartida por varios comercios el reporte mezclaba las ventas de
     * todos.
     */
    function index(Request $request) {

        /** Dueño de la cuenta (para un empleado, devuelve el id de su dueño). */
        $owner_id = $this->userId();

        $client_id = $request->client_id;
        $provider_id = $request->provider_id;
        $category_id = $request->category_id;
        $address_id = $request->address_id;
        $cantidad_resultados = $request->cantidad_resultados;
        $orden = $request->orden;
        $sale_channel_id = $request->sale_channel_id;

        /*
         * Acepta fechas en formato Y-m-d (nuevo) o Y-m (legado).
         * Carbon::parse() maneja ambos formatos sin configuración adicional.
         */
        $mes_inicio_raw = $request->fecha_inicio ?: $request->mes_inicio;
        $mes_fin_raw    = $request->fecha_fin    ?: $request->mes_fin;

        $mes_inicio = Carbon::parse($mes_inicio_raw)->startOfDay();
        $mes_fin    = Carbon::parse($mes_fin_raw)->endOfDay();

        Log::info('mes_inicio: '.$mes_inicio->format('d/m/y'));
        Log::info('mes_fin: '.$mes_fin->format('d/m/y'));

        /*
         * La venta tiene que ser del dueño, no estar borrada y no ser una contenedora de
         * consolidación AFIP (mismo criterio que ConsultasSistemaIaHelper::mas_vendidos()).
         * El `whereNull('sales.deleted_at')` es explícito porque la relación `sale()` de
         * ArticlePurchase es `withTrashed()`: sin él, una venta borrada seguía sumando.
         */
        $purchases = ArticlePurchase::whereBetween('article_purchases.created_at', [$mes_inicio, $mes_fin])
                                ->whereHas('sale', function($query) use ($owner_id) {
                                    $query->where('sales.user_id', $owner_id)
                                          ->whereNull('sales.deleted_at')
                                          ->soloVentasReales();
                                })
                                ->with('article.provider', 'article.category');

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

        if (!is_null($address_id)) {
            $purchases = $purchases->where('address_id', $address_id);
        }

        if (
            !is_null($sale_channel_id)
            && $sale_channel_id !== 0
        ) {
            $purchases = $purchases->where('sale_channel_id', $sale_channel_id);
        }

        $purchases = $purchases->get();

        /** Todos los artículos vendidos en el período, ya ordenados (sin recortar). */
        $agrupados = $this->agrupar_articulos($purchases, $orden);

        $totales = $this->get_totales($agrupados);

        $categories = $this->get_categories($agrupados, $orden);

        $providers = $this->get_providers($agrupados, $orden);

        $models = $this->recortar($agrupados, $cantidad_resultados);
        
        return response()->json([
            'models'        => $models,
            'categories'    => $categories,
            'providers'     => $providers,
            'totales'       => $totales,
        ], 200);
    }

    /**
     * Agrupa los renglones vendidos por artículo y los ordena según `$orden`.
     *
     * Devuelve todas las filas del período. El tercer parámetro queda por compatibilidad: si viene,
     * se recorta igual que en `recortar()`; `index()` ya no lo pasa, porque los totales, las
     * categorías y los proveedores se calculan sobre la lista completa.
     */
    function agrupar_articulos($purchases, $orden, $cantidad_resultados = null) {

        $agrupados = [];

        foreach ($purchases as $purchase) {

            $article_id = $purchase->article_id;

            if (isset($agrupados[$article_id])) {

                $agrupados[$article_id]['unidades_vendidas']    += $purchase->amount;
                $agrupados[$article_id]['price']                += $purchase->price * $purchase->amount;
                $agrupados[$article_id]['cost']                 += $purchase->cost * $purchase->amount;

                $agrupados[$article_id]['price_dolar']          += $purchase->price_dolar * $purchase->amount;
                $agrupados[$article_id]['cost_dolar']           += $purchase->cost_dolar * $purchase->amount;

                // if ($article_id == 1) {
                //     Log::info('Se SUMA a prensa con price = '.$purchase->price);
                // }
           
            } else {

                $agrupados[$article_id] = [
                    'article_id'        => $purchase->article->id, 
                    'bar_code'          => $purchase->article->bar_code, 
                    'provider_code'     => $purchase->article->provider_code, 
                    'article_name'      => $purchase->article->name, 
                    'category'          => $this->get_relation($purchase, 'category'), 
                    'provider'          => $this->get_relation($purchase, 'provider'), 
                    'article'           => $purchase->article, 
                    'unidades_vendidas'            => $purchase->amount,
                    'price'             => $purchase->price * $purchase->amount,
                    'cost'              => $purchase->cost * $purchase->amount,
                    'price_dolar'       => $purchase->price_dolar * $purchase->amount,
                    'cost_dolar'        => $purchase->cost_dolar * $purchase->amount,
                ];

                if ($article_id == 1) {
                    Log::info('Se inico prensa con price = '.$purchase->price);
                }
            }
        }

        foreach ($agrupados as $article_id => $agrupado) {

            $agrupados[$article_id]['beneficio'] = (float)$agrupados[$article_id]['price'] - (float)$agrupados[$article_id]['cost'];
            $agrupados[$article_id]['beneficio_dolar'] = (float)$agrupados[$article_id]['price_dolar'] - (float)$agrupados[$article_id]['cost_dolar'];
        }

        $agrupados = array_values($agrupados);

        $agrupados = $this->ordenar($agrupados, $orden, 'article_id');

        return $this->recortar($agrupados, $cantidad_resultados);
    }

    /**
     * Deja las primeras `$cantidad_resultados` filas (ya ordenadas).
     *
     * Exactamente esa cantidad: hasta el 10/10/2026 era `array_slice(..., $cantidad - 1)` y con 10
     * devolvía 9 (y con null, `slice(0, -1)`, sacaba la última). Si la cantidad no es un entero
     * mayor que cero (null, vacía, 0, negativa, texto) se devuelven todas.
     */
    function recortar($agrupados, $cantidad_resultados) {

        $cantidad = filter_var($cantidad_resultados, FILTER_VALIDATE_INT);

        if ($cantidad === false || $cantidad <= 0) {
            return $agrupados;
        }

        return array_slice($agrupados, 0, $cantidad);
    }

    /**
     * Totales de TODO el período (no de la lista recortada): lo que el dueño lee como "Total" y
     * "Unidades vendidas". Floats redondeados a 2 decimales; `cantidad_articulos` = artículos
     * distintos vendidos en el período.
     */
    function get_totales($agrupados) {

        $totales = [
            'unidades_vendidas'     => 0,
            'price'                 => 0,
            'cost'                  => 0,
            'beneficio'             => 0,
            'price_dolar'           => 0,
            'cost_dolar'            => 0,
            'beneficio_dolar'       => 0,
        ];

        foreach ($agrupados as $agrupado) {

            $totales['unidades_vendidas']   += (float)$agrupado['unidades_vendidas'];
            $totales['price']               += (float)$agrupado['price'];
            $totales['cost']                += (float)$agrupado['cost'];
            $totales['price_dolar']         += (float)$agrupado['price_dolar'];
            $totales['cost_dolar']          += (float)$agrupado['cost_dolar'];
        }

        $totales['beneficio']       = $totales['price'] - $totales['cost'];
        $totales['beneficio_dolar'] = $totales['price_dolar'] - $totales['cost_dolar'];

        foreach ($totales as $clave => $valor) {

            $totales[$clave] = round((float)$valor, 2);
        }

        $totales['cantidad_articulos'] = count($agrupados);

        return $totales;
    }

    /**
     * Ordena filas por unidades vendidas según `$orden` ('mayor-menor' / 'menor-mayor'); con
     * cualquier otro valor las deja como vienen.
     *
     * Compara con `<=>` sobre float: la resta que había antes (`$b - $a`) se casteaba a int en
     * `usort` y 1,5 empataba con 1,0 (artículos por kilo, balanzas). Como `usort` de PHP 7.4 no es
     * estable, el empate se desarma siempre igual: `price` de mayor a menor y después
     * `$campo_desempate` (el id del artículo de menor a mayor, o el nombre alfabético).
     */
    function ordenar($filas, $orden, $campo_desempate) {

        if ($orden == 'mayor-menor') {

            $direccion = -1;

        } else if ($orden == 'menor-mayor') {

            $direccion = 1;

        } else {

            return $filas;
        }

        usort($filas, function($a, $b) use ($direccion, $campo_desempate) {

            $por_unidades = ((float)$a['unidades_vendidas'] <=> (float)$b['unidades_vendidas']) * $direccion;

            if ($por_unidades != 0) {
                return $por_unidades;
            }

            $por_price = (float)$b['price'] <=> (float)$a['price'];

            if ($por_price != 0) {
                return $por_price;
            }

            if ($campo_desempate == 'article_id') {
                return (int)$a['article_id'] <=> (int)$b['article_id'];
            }

            return strcmp((string)$a[$campo_desempate], (string)$b[$campo_desempate]);
        });

        return $filas;
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

        return $this->ordenar($categories, $orden, 'category_name');
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

        return $this->ordenar($providers, $orden, 'provider_name');
    }
}
