<?php

namespace App\Http\Controllers;

use App\Exports\SalesExport;
use App\Http\Controllers\AfipWsController;
use App\Http\Controllers\ArticlePerformanceController;
use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\CommonLaravel\Helpers\Numbers;
use App\Http\Controllers\Helpers\AfipHelper;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\CartArticleAmountInsificienteHelper;
use App\Http\Controllers\Helpers\CurrentAcountHelper;
use App\Http\Controllers\Helpers\InventoryLinkageHelper;
use App\Http\Controllers\Helpers\Order\CreateSaleOrderHelper;
use App\Http\Controllers\Helpers\RecalculateCurrentAcountsHelper;
use App\Http\Controllers\Helpers\RecipeHelper;
use App\Http\Controllers\Helpers\SaleHelper;
use App\Http\Controllers\Helpers\providerOrder\NewProviderOrderHelper;
use App\Http\Controllers\Stock\StockMovementController;
use App\Jobs\ProcessCheckInventoryLinkages;
use App\Jobs\ProcessCheckSaldos;
use App\Jobs\ProcessRecalculateCurrentAcounts;
use App\Jobs\ProcessSetStockResultante;
use App\Jobs\SetSalesTerminadaAtJob;
use App\Models\Article;
use App\Models\ArticlePriceTypeMoneda;
use App\Models\Budget;
use App\Models\Buyer;
use App\Models\Caja;
use App\Models\Category;
use App\Models\Cheque;
use App\Models\Client;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\CurrentAcountCurrentAcountPaymentMethod;
use App\Models\CurrentAcountPaymentMethod;
use App\Models\DefaultPaymentMethodCaja;
use App\Models\Image;
use App\Models\InventoryLinkage;
use App\Models\MeliOrder;
use App\Models\MercadoLibreToken;
use App\Models\OnlineConfiguration;
use App\Models\Order;
use App\Models\OrderProduction;
use App\Models\PriceType;
use App\Models\Provider;
use App\Models\ProviderOrder;
use App\Models\Recipe;
use App\Models\Sale;
use App\Models\SaleModification;
use App\Models\StockMovement;
use App\Models\SubCategory;
use App\Models\User;
use App\Models\UserConfiguration;
use App\Services\MercadoLibre\OrderDownloaderService;
use App\Services\MercadoLibre\ProductoDownloaderService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class HelperController extends Controller
{

    function callMethod($method, $param = null) {
        $this->{$method}($param);
    }

    function set_articles_slug() {
        $articles = Article::where('user_id', env('USER_ID'))
                            ->whereNull('slug')
                            ->get();

        echo count($articles).' articulos <br>';

        foreach ($articles as $article) {
            
            $article->slug = ArticleHelper::slug($article->name);
            $article->timestamps = false;
            $article->save();
            echo 'article '.$article->id.' ok <br>';
        }
        echo 'Listo';
    }

    function articulos_eliminados() {
        $grupos = Article::onlyTrashed()
                    ->select(DB::raw('deleted_at, COUNT(*) as total'))
                    ->whereDate('deleted_at', '>=', '2025-12-03')
                    ->groupBy('deleted_at')
                    ->get();

        $restaurados_total = 0;

        $total = 0;

        foreach ($grupos as $grupo) {

            $total += $grupo->total;
            // if ($grupo->total > 300) {

                echo("Restaurando {$grupo->total} productos eliminados en: {$grupo->deleted_at} <br>");
            // }

            // Paso 2: Restaurar productos con esa fecha exacta de deleted_at
            // $restaurados = Producto::onlyTrashed()
            //     ->where('deleted_at', $grupo->deleted_at)
            //     ->update(['deleted_at' => null]);

            // $restaurados_total += $restaurados;
        }

        echo 'Listo '.$total;
    }

    function ventas_mal($user_id = null) {
        
        if (!$user_id) {

            $user_id = env('USER_ID'); 
        }

        $sales = Sale::where('user_id', $user_id)
                        ->orderBy('id', 'DESC')
                        ->take(5000)
                        ->get();


        foreach ($sales as $sale) {
            $total_1 = $sale->total;
            $total_2 = SaleHelper::getTotalSale($sale);
            // $total_1 = abs($sale->total);
            // $total_2 = abs(SaleHelper::getTotalSale($sale));

            if (abs($total_1 - $total_2) > 0.01) {
                echo $sale->num. '<br>';
                echo $total_1. '<br>';
                echo $total_2. '<br>';

                echo '<br>';
            }
        }
        echo 'TERMINO';
    }

    function iniciar_demo() {

        
        $sales = Sale::orderBy('id', 'DESC')
                        ->take(30)
                        ->get();

        echo(count($sales).' ventas <br>');

        $index = count($sales);

        foreach ($sales as $sale) {
            
            $sale->timestamps = false;
        
            $sale->created_at = Carbon::now();

            $sale->save();
            
            if ($sale->client_id) {

                SaleHelper::updateCurrentAcountsAndCommissions($sale);
            }
            echo('Venta N° '.$sale->num.' ok <br>');

        }

        echo 'Termino';
    }

    function set_cajas() {
        $payment_methods = CurrentAcountPaymentMethod::get();

        $employees = User::where('owner_id', env('USER_ID'))
                        ->get();

        $num = 1;
        foreach ($payment_methods as $payment_method) {
            
            foreach ($employees as $employee) {
                
                $model = [
                    'num'           => $num,
                    'name'          => $payment_method->name,
                    'employee_id'   => $employee->id,
                    'address_id'    => $employee->address_id,
                    'user_id'       => env('USER_ID'),
                    'saldo'         => 0,
                ];

                $num++;


                $caja = Caja::create($model);

                $default = DefaultPaymentMethodCaja::create([
                    'caja_id'                           => $caja->id,
                    'current_acount_payment_method_id'  => $payment_method->id,
                    'address_id'                        => $caja->address_id,
                    'employee_id'                       => $employee->id,
                    'user_id'                           => env('USER_ID'),
                ]);

                echo 'Se creo caja '.$caja->name.' para '.$employee->name;
                echo '<br>';
            }
        }
    }

    function articulos_redondeados() {
        $articles = Article::whereRaw('MOD(final_price, 10) = 0')->get();

        foreach ($articles as $article) {

            if ($article->final_price > 0) {
                
                echo $article->id. ' <br>';
                ArticleHelper::setFinalPrice($article, env('USER_ID'));
            }
        }
    }

    function set_precios_golden() {
        $articles = Article::all();

        $price_types = PriceType::all();

        foreach ($articles as $article) {
            if (count($article->price_type_monedas) == 0) {


                echo '<strong>'.$article->id.' no tiene </strong> <br>';
                
                foreach ($price_types as $price_type) {
                    for ($moneda_id=1; $moneda_id < 3; $moneda_id++) { 
                        ArticlePriceTypeMoneda::create([
                            'article_id'     => $article->id,
                            'moneda_id'     => $moneda_id,
                            'price_type_id'     => $price_type->id,
                        ]);
                    }
                }
                
            } else {
                echo $article->id.' SI tiene <br>';
            }
        }
        echo 'Listo';
    }

    function set_sales() {
        
        $meli_orders = MeliOrder::all();

        $user = User::find(env('USER_ID'));

        foreach ($meli_orders as $meli_order) {
            CreateSaleOrderHelper::save_sale($meli_order, $this, false, true, $user);
        }

    }

    function add_price_types_to_articles() {
        $articles = Article::where('user_id', env('USER_ID'))->get();

        $price_types = PriceType::all();

        foreach ($articles as $article) {
            
            foreach ($price_types as $price_type) {
                
                $article_price_type = $article->price_types()->find($price_type->id);

                if (!$article_price_type) {
                    $article->price_types()->attach($price_type->id);

                    echo $article->name.' se le agrego '.$price_type->name.' <br>';
                    echo '----- <br>';
                }
            }
        }

        echo 'Listo';

    }

    function importar_productos_ml() {
        $service = new ProductoDownloaderService(env('USER_ID'));

        $token = MercadoLibreToken::where('user_id', env('USER_ID'))->first();

        $service->importar_productos($token->meli_user_id);
    }

    function importar_orders_ml() {
        $service = new OrderDownloaderService(env('USER_ID'));

        // $token = MercadoLibreToken::where('user_id', env('USER_ID'))->first();

        $service->get_all_orders();
    }

    function check_saldos() {
        $user = User::find(env('USER_ID'));

        $credit_accounts = CreditAccount::where('user_id', $user->id) 
                                        ->get();

        foreach ($credit_accounts as $credit_account) {
            CurrentAcountHelper::check_saldos_y_pagos($credit_account->id);
            if ($credit_account->model) {

                echo 'Listo '.$credit_account->model->name.' <br>';
            }
        }
        echo 'Termino';

    }

    function examinar_sale($id) {
        $sale = Sale::where('id', $id)
                    ->withAll()
                    ->first();

        var_dump($sale);

    }

    function check_total_facturado() {
        $sales = Sale::where('user_id', env('USER_ID'))
                        ->orderBy('id', 'ASC')
                        ->get();

        foreach ($sales as $sale) {
            
            if (
                $sale->total_a_facturar
                && abs($sale->total_a_facturar) != abs($sale->total)
            ) {
                echo 'Venta N° '.$sale->num.'. Total: '.Numbers::price($sale->total).'. Facturado: '.Numbers::price($sale->total_a_facturar).' <br>';
            }
        }

        echo 'Listo';

    }

    // function check_saldos() {
    //     $clients = Client::where('user_id', env('USER_ID'))
    //                         ->get();

    //     foreach ($clients as $client) {
    //         foreach ($client->credit_accounts as $credit_account) {
    //             CurrentAcountHelper::check_saldos_y_pagos($credit_account->id);
    //         }
    //     }



    //     $providers = Provider::where('user_id', env('USER_ID'))
    //                         ->get();
    //     foreach ($providers as $provider) {
    //         foreach ($provider->credit_accounts as $credit_account) {
    //             CurrentAcountHelper::check_saldos_y_pagos($credit_account->id);
    //         }
    //     }
    // }

    function corregir_stock_ferretotal($article_id = null) {
        $articles = Article::where('user_id', env('USER_ID'));

        if (!is_null($article_id)) {
            $articles->where('id', $article_id);
        }
        $articles = $articles->get();

        $fecha_limite = Carbon::parse('2025-09-09 00:00:00');

        foreach ($articles as $article) {
            
            $stock_movements = StockMovement::where('article_id', $article->id)
                                            ->orderBy('id', 'ASC')
                                            ->get();

            $primer_detectado = false;
            $stock = 0;

            foreach ($stock_movements as $stock_movement) {
                
                if ($stock_movement->created_at < $fecha_limite) {
                    $stock_movement->delete();
                } else if (!$primer_detectado) {

                    $stock_movement->stock_resultante = $stock_movement->amount;
                    $stock_movement->save();
                    $primer_detectado = true;
                    $stock = $stock_movement->amount;
                } else {
                    
                    $stock += $stock_movement->amount;

                    $stock_movement->stock_resultante = $stock;
                    $stock_movement->save();
                }
            }

            echo 'Corregido '.$article->name.' <br>';
        }
        echo 'Listo';
    }

    /*
        Controla que se haya descontado el stock correspondiente de las ventas
    */
    function check_ventas_stock_movements() {
        $user = User::whereNull('owner_id')->first();

        $sales = Sale::where('user_id', $user->id)
                        ->orderBy('id', 'DESC')
                        ->get();

        foreach ($sales as $sale) {
            
            foreach ($sale->articles as $article) {
                
                $stock_movement = StockMovement::where('article_id', $article->id)
                                                ->where('sale_id', $sale->id)
                                                ->first();

                if ($stock_movement) {

                    if (abs($stock_movement->amount) != abs($article->pivot->amount)) {

                        echo $article->name.' venta id '.$sale->id.' <br>';
                        echo 'Se vendieron '.$article->pivot->amount.' y se registraron '.$stock_movement->amount.' <br>';
                        echo '<br>';
                    }
                } else if (!is_null($article->stock)) {


                    $primer_mov = StockMovement::where('article_id', $article->id)
                                                    ->orderBy('id', 'ASC')
                                                    ->first();

                    if ($primer_mov->created_at->gt($sale->created_at)) {

                        echo 'No hay stock movement para sale num '.$sale->num.' article id '.$article->id;
                        echo '<br>';
                    }
                }
            }
        }
        echo 'Listo';
    }

    function liberar_ventas($user_id = 800) {
        $sales = Sale::whereNotNull('actualizandose_por_id')
                        ->where('user_id', $user_id)
                        ->get();

        foreach ($sales as $sale) {
            $sale->actualizandose_por_id = null;
            $sale->timestamps = false;
            $sale->save();
            echo 'Venta N° '.$sale->num.' liberada </br>';
        }

        echo 'Listo';
    }

    function bar_code_repetidos($user_id = null) {

        if (!$user_id) {
            $user_id = env('USER_ID');
        }
        
        $articles = Article::where('user_id', $user_id)
                            ->get();

        $repetidos = $articles->groupBy('bar_code')
                        ->filter(function ($items) {
                            return $items->count() > 1 && !is_null($items->first()->bar_code);
                        })
                        ->flatten();

        foreach ($repetidos as $article) {
            echo $article->name.' | bar_code: '.$article->bar_code.' repetido <br>';
        }
        echo 'Listo';
    }

    function cheques_repetidos($user_id = null) {

        if (!$user_id) {
            $user_id = env('USER_ID');
        }

        $cheques = Cheque::where('user_id', $user_id)->get();

        // Agrupar por combinación de número y tipo
        $repetidos = $cheques->groupBy(function ($item) {
                                return $item->numero . '|' . $item->tipo;
                            })
                            ->filter(function ($items) {
                                return $items->count() > 1 && !is_null($items->first()->numero);
                            })
                            ->flatten();

        // Borramos duplicados manteniendo el primero
        $agrupados = [];
        foreach ($repetidos as $cheque) {
            $key = $cheque->numero . '|' . $cheque->tipo;

            if (!isset($agrupados[$key])) {
                $agrupados[$key] = true; // mantenemos el primero
                echo $cheque->numero.' | tipo: '.$cheque->tipo.' -> OK<br>';
            } else {
                // $cheque->delete(); // eliminamos los repetidos
                echo $cheque->numero.' | tipo: '.$cheque->tipo.' -> ELIMINADO<br>';
            }
        }

        echo 'Listo';
    }

    function provider_code_repetidos($user_id = null) {
        if (!$user_id) {
            $user_id = env('USER_ID');
        }

        $articles = Article::where('user_id', $user_id)->get();

        $agrupados = $articles->groupBy('provider_code')
            ->filter(function ($items, $provider_code) {
                return $items->count() > 1 && !is_null($provider_code);
            });

        foreach ($agrupados as $provider_code => $articulos) {

            echo "provider_code: $provider_code repetido en los siguientes artículos:<br>";

            foreach ($articulos as $article) {
                echo "- {$article->name} (ID: {$article->id}), updated_at: {$article->updated_at->format('d/m/y')} <br>";
            }

            $mas_reciente = $articulos->sortByDesc('updated_at')->first();
            $a_eliminar = $articulos->filter(function ($articulo) use ($mas_reciente) {
                return $articulo->id !== $mas_reciente->id;
            });

            foreach ($a_eliminar as $articulo) {
                echo "Eliminando artículo ID {$articulo->id} con updated_at: {$article->updated_at->format('d/m/y')}<br>";
                $articulo->delete();
            }

            echo "Se conservó el artículo ID {$mas_reciente->id} actualizado el {$mas_reciente->updated_at}<br><br>";
        }

        echo "Listo. Se eliminaron los duplicados.";
    }

    // ELimina los mas viejos, deja el mas nuevo
    function eliminar_provider_code_repetidos($user_id = null) {

        if (!$user_id) {
            $user_id = env('USER_ID');
        }
        
        $articles = Article::where('user_id', $user_id)
                            // ->whereNull('bar_code')
                            ->get();

        $repetidos = $articles->groupBy('provider_code')
                        ->filter(function ($items) {
                            return $items->count() > 1 && !is_null($items->first()->provider_code);
                        })
                        ->flatten();

        foreach ($repetidos as $article) {
            echo $article->name.' | provider_code: '.$article->provider_code.' repetido <br>';
        }
        echo 'Listo';
    }

    function restablecer_sale_modifications($sale_modification_id) {

        $sale_modification = SaleModification::find($sale_modification_id);

        $sale = Sale::create([
            'client_id'     => $sale_modification->sale->client_id,
            'price_type_id' => $sale_modification->sale->price_type_id,
            'user_id'       => $sale_modification->sale->user_id,
        ]);

        foreach ($sale_modification->articulos_antes_de_actualizar as $article) {
            $sale->articles()->attach($article->id, [
                'amount'                => $article->pivot->amount,
                'cost'                  => $article->cost,
                'price'                 => $article->final_price,
                'returned_amount'       => null,
                'delivered_amount'      => null,
                'discount'              => null,
                'checked_amount'        => null,
                'article_variant_id'    => null,
                'variant_description'    => null,
                'price_type_personalizado_id'    => null,
                'created_at'            => Carbon::now(),
            ]);

            echo 'Agregando '.$article->name.' <br>';
        }

        echo 'Listo';
    } 

    function set_costos_promocion_vinotecas() {
        $promos = PromocionVinoteca::all();
        foreach ($promos as $promo) {
            // $promo->
        }
    }

    function corregir_stock($user_id) {
        $articulos_mal = [];
        $articles = Article::where('user_id', $user_id)
                            ->get();

        echo count($articles).' articulos';      
        echo '<br>';

        foreach ($articles as $article) {
            
            $last_stock_movement = StockMovement::where('article_id', $article->id)
                                                ->orderBy('id', 'DESC')
                                                ->first();

            if ($last_stock_movement) {
                $stock_resultante = $last_stock_movement->stock_resultante;
                if ($article->stock != $stock_resultante) {

                    $article->stock = $stock_resultante;
                    $article->timestamps = false;
                    $article->save();

                    echo 'Se corrigio article id'.$article->id;
                    echo '<br>';
                }
            }
        }

        echo 'Listo';
    }

    function set_articles_bar_codes() {
        $articles = Article::whereNull('bar_code')
                            ->orderBy('id', 'ASC')
                            ->get();

        echo count($articles).' articulos <br>';

        foreach ($articles as $article) {
            $article->bar_code = $article->id;
            $article->timestamps = false;
            $article->save();
        }
        echo 'Listo';
    }

    function set_renacer_articles_bar_codes() {
        $articles = Article::orderBy('id', 'ASC')
                            ->get();

        foreach ($articles as $article) {
            $article->bar_code = $article->id;
            $article->timestamps = false;
            $article->save();
        }
        echo 'Listo';
    }

    function sales_sin_stock_movements($user_id) {
        $sales = Sale::whereDate('created_at', '>', Carbon::today()->subDays(7))
                        ->where('user_id', $user_id)
                        ->get();

        echo count($sales).' ventas';
        echo '<br>';

        $sin_stock_movements = [];

        foreach ($sales as $sale) {

            foreach ($sale->articles as $article) {

                $stock_movement = StockMovement::where('sale_id', $sale->id)
                                                ->where('article_id', $article->id)
                                                ->first();
                if (!$stock_movement) {
                    $sin_stock_movements[] = $sale;
                }
            }
        }

        foreach ($sin_stock_movements as $sale) {
            echo $sale->num;
            echo '<br>';
        }
        echo 'Listo';
    }


    function precios_feito($price) {
        // echo 'hola';
        // echo '<br>';
        $articles = Article::where('user_id', 1)
                            ->where('final_price', $price)
                            // ->take(10)
                            ->get();

        echo count($articles).' articles';
        echo '<br>';
        // return;

        // sleep(5);

        foreach ($articles as $article) {
            
            echo 'Se va a cmabiar '.$article->name.' de '.$article->final_price.' a '.$article->previus_final_price;
            echo '<br>';

            // sleep(5);
            
            $article->price         = $article->previus_final_price;
            $article->final_price   = $article->previus_final_price;
            $article->timestamps = false;
            $article->save();

        }
        echo 'Termino';
    }

    function diff_entre_order_y_sale() {
        $order_id = 11;
        $sale_id = 121;

        $order = Order::find($order_id);
        $sale = Sale::find($sale_id);

        $articles_order = $order->articles()->pluck('article_order.amount', 'articles.id')->toArray();
        $articles_sale = $sale->articles()->pluck('article_sale.amount', 'articles.id')->toArray();
    

        foreach ($articles_order as $id => $amount_order) {

            if (isset($articles_sale[$id]) && $articles_sale[$id] != $amount_order) {
                $cambiados[] = [
                    'article' => Article::find($id),
                    'article_sale' => Article::find($id),
                    'amount_before' => $amount_order,
                    'amount_after' => $articles_sale[$id],
                    'unidades_quitadas' => $amount_order - $articles_sale[$id],
                ]; 
            }
        }

        $total = 0;
        foreach ($cambiados as $cambiado) {
            echo $cambiado['article']->name.':';
            echo '<br>';
            echo 'Se sacaron: '.$cambiado['unidades_quitadas'];
            echo '<br>';
            $total_sacado = $cambiado['unidades_quitadas']*$sale->articles()->where('articles.id', $cambiado['article']->id)->first()->pivot->price;
            echo 'Total: $'.$total_sacado;
            $total += $total_sacado;
            echo '<br>';
            echo '<br>';
        }
        echo 'Total: '.$total;
        // dd($cambiados);
    }

    function diferencia_en_actualisaciones($sale_id) {

        $sale = Sale::find($sale_id);
        $index = 1;
        foreach ($sale->sale_modifications as $sale_modification) {
            
            $articles1 = $sale_modification->articulos_antes_de_actualizar()->pluck('articles.id')->toArray(); 
            $articles2 = $sale_modification->articulos_despues_de_actualizar()->pluck('articles.id')->toArray();

            $eliminados = Article::whereIn('id', array_diff($articles1, $articles2))->get();
            $agregados = Article::whereIn('id', array_diff($articles2, $articles1))->get();

            echo "Actualizacion $index:";
            echo '<br>';
            echo 'Articulos eliminados:';
            echo '<br>';
            // dd($eliminados);

            foreach ($eliminados as $article) {
                echo $article->name;
                echo '<br>';

            }

            echo '<br>';
            echo '***************';
            echo 'Articulos agrergados:';
            echo '<br>';

            foreach ($agregados as $article) {
                echo $article->name;
                echo '<br>';

            }
            echo '<br>';
            echo '***************';
            echo '<br>';
            echo '<br>';
            echo '<br>';
            $index++;
        }
    }

    function articulos_eliminados_de_ventas_sin_stock_movement($user_id) {
        $errores = [];

        $concepto_venta = 3;
        $concepto_se_elimino_de_venta = 5;
        $concepto_se_elimino_la_venta = 6;

        $ventasConMovimientos = StockMovement::where('concepto_stock_movement_id', $concepto_venta)
                                            ->where('user_id', $user_id)
                                            ->where('created_at', '>=', Carbon::today()->subDays(8))
                                            ->get()
                                            ->groupBy('sale_id');

        foreach ($ventasConMovimientos as $saleId => $movimientos) {
            // Obtenemos la venta
            $venta = Sale::with('articles')->withTrashed()->find($saleId);

            foreach ($movimientos as $mov) {
                $articleId = $mov->article_id;

                // Verificamos si el artículo aún está en la venta
                $articuloEnVenta = $venta->articles->contains('id', $articleId);

                // Verificamos si existe un movimiento de tipo "Se eliminó de la Venta"
                $existeDevolucion = StockMovement::where('sale_id', $saleId)
                                                ->where('article_id', $articleId)
                                                ->where('concepto_stock_movement_id', $concepto_se_elimino_de_venta)
                                                ->exists();


                // Si el artículo ya no está en la venta y no hay movimiento de devolución, es un error
                if (!$articuloEnVenta && !$existeDevolucion) {



                    // Verificamos si existe un movimiento de tipo "Se eliminó la Venta"
                    $existe_eliminacion = StockMovement::where('sale_id', $saleId)
                                                    ->where('article_id', $articleId)
                                                    ->where('concepto_stock_movement_id', $concepto_se_elimino_la_venta)
                                                    ->exists();



                    if (!$articuloEnVenta && !$existe_eliminacion) {
                        
                        $article = Article::withTrashed()->find($articleId);

                        $errores[] = [
                            'sale_num' => $venta->num,
                            'article_num' => $article->num,
                            'sale_id' => $venta->id,
                            'article_id' => $article->id,
                            'amount'    => abs($mov->amount),
                        ];
                    }
                }
            }
        }

        // $this->crear_stock_movement_de_eliminacion($errores);

        dd($errores);
    }

    function crear_stock_movement_de_eliminacion($errores) {
        $ct = new StockMovementController();
        foreach ($errores as $error) {
            $data = [
                'model_id'                          => $error['article_id'],
                'sale_id'                           => $error['sale_id'],
                'concepto_stock_movement_name'      => 'Se elimino de la venta',
                'amount'                            => $error['amount']
            ];  

            $ct->crear($data, false);
        }
    }

    function set_sales_seller_id($user_id = 500) {
        $sales = Sale::where('user_id', $user_id)
                        ->whereNull('seller_id')
                        ->orderBy('created_at', 'ASC')
                        ->get();

        echo count($sales).' ventas';
        echo '<br>';


        foreach ($sales as $sale) {

            if (
                $sale->client
                && $sale->client->seller_id
            ) {
                $sale->seller_id = $sale->client->seller_id;
                $sale->timestamps = false;
                $sale->save();
            }

            if ($sale->seller_id) {
                SaleHelper::deleteSellerCommissionsFromSale($sale);
                SaleHelper::crear_comision($sale);
                echo 'Comision para venta N° '.$sale->num;
                echo '<br>';
            }
            
        }
        echo 'TERMINO';
    }

    function set_sale_comissions($user_id = 500) {
        $sales = Sale::where('created_at', '>=', Carbon::today())
                        ->where('user_id', $user_id)
                        ->orderBy('created_at', 'ASC')
                        ->get();

        echo count($sales).' ventas';
        echo '<br>';


        foreach ($sales as $sale) {
            
            SaleHelper::deleteSellerCommissionsFromSale($sale);
            SaleHelper::crear_comision($sale);
            echo 'Comision para venta N° '.$sale->num;
            echo '<br>';
        }
        echo 'TERMINO';
    }

    function articles_sin_stock_y_con_direcciones() {

        $articles = Article::whereNull('stock')
                            ->whereHas('addresses')
                            ->get();
        foreach ($articles as $article) {
            
            $article->addresses()->sync([]);
        }
        echo count($articles).' articulos con direcciones y sin stock'; 
    }

    function articulos_sin_address() {
        $articles = Article::whereDoesntHave('addresses')
                            ->get();
        echo count($articles).' articulos sin direccion'; 
    }

    function restaurar_sales($ids) {

        $ids = explode('-', $ids);
        
        $ct = new StockMovementController();
        
        foreach ($ids as $id) {
            $sale = Sale::where('id', $id)
                            ->withTrashed()
                            ->first();

            foreach ($sale->articles as $article) {

                $request = new \Illuminate\Http\Request();

                $request->model_id = $article->id;
                $request->from_address_id = $sale->address_id;
                $request->amount = -$article['pivot']['amount'];
                $request->sale_id = $sale->id;
                $request->concepto = 'Restauracion Venta N° '.$sale->num;

                $ct->store($request, false);
                echo 'Mov para '.$article->name. '. Num: '.$article->num;
                echo '<br>';
            }

            $sale->deleted_at = null;
            $sale->timestamps = false;
            $sale->save();
            echo 'Se restauro sale num '.$sale->num;
            echo '<br>';
            echo '<br>';
        }
        echo 'Termino';
    }

    function arreglar_restaurar_sales($ids) {

        $ids = explode('-', $ids);
        
        $ct = new StockMovementController();
        
        foreach ($ids as $id) {
            $sale = Sale::where('id', $id)
                            ->first();

            foreach ($sale->articles as $article) {

                $request = new \Illuminate\Http\Request();

                $request->model_id = $article->id;
                $request->from_address_id = $sale->address_id;
                $request->amount = -((int)$article['pivot']['amount'] * 2);
                $request->sale_id = $sale->id;
                $request->concepto = 'Restauracion Venta N° '.$sale->num;

                $ct->store($request, false);
                echo 'Mov para '.$article->name. '. Num: '.$article->num;
                echo '<br>';
            }
            
            echo 'Se restauro sale num '.$sale->num;
            echo '<br>';
            echo '<br>';
        }
        echo 'Termino';
    }

    function set_articles_num($user_id) {
        $articles = Article::where('user_id', $user_id)
                            ->whereNull('num')
                            ->orderBy('created_at', 'ASC')
                            ->get();

        foreach ($articles as $article) {
            
            $article->num = $this->num('articles', $user_id);
            $article->timestamps = false;
            $article->save();
            echo 'se actualizo '.$article->name;
            echo '<br>';

        }

        echo 'Lisot';
    }

    function pasar_cantidades_a_notas($user_id) {
        $orders = ProviderOrder::where('user_id', $user_id)
                                ->where('created_at', '>', Carbon::today()->subDays(2))
                                ->orderBy('created_at', 'ASC')
                                ->get();

        foreach ($orders as $provider_order) {
            
            foreach ($provider_order->articles as $article) {
                $pivot = $article->pivot;

                $pivot->notes = $pivot->amount;

                $pivot->save();

            }
            echo 'Se actualizo pedido N° '.$provider_order->num;
            echo '<br>';
        }
        echo 'Listo';
    }

    function set_buyers_password($user_id) {
        $buyers = Buyer::where('user_id', $user_id)
                        ->get();

        foreach ($buyers as $buyer) {
            
            if (!is_null($buyer->comercio_city_client)) {

                $buyer->password = bcrypt($buyer->comercio_city_client->num);
                $buyer->timestamps = false;
                $buyer->save();
                echo 'Se actualizo '.$buyer->name;
                echo '<br>';
            }
        }

        echo 'Listo';
    }

    function recalcular_movimientos_stock() {

        $articles_name = [
            'CINTA PVC SIN ADHESIVO BLANCA  (IMP.)',
            'TERMOSTATO COOLTECH (B23626-2S)  P/ 2 FRIOS',
            'AISLACION NEGRA  1/4 (6X6) COOLTECH (PRECIO X METRO)',
            
            // 'PRESOST. BIF. MACHO, R-134A, USA (SW/9066F)',
            // 'PLAQUETA (MC-P03) FASE 2 PLUS',
            // 'PLAQUETA (MC-P04) FASE 3 DREAN',
            // 'AISLACION NEGRA 3/8 (10X6) COOLTECH (CO-D10) (PRECIO X METRO)',
            // 'PLAQUETA (MC-L24) GAFA 6500/7500 FASE 1 / ELECTROLUX ELAV 9700',
            // 'PLAQUETA (MC-L25) GAFA 6500/7500 FASE 2/ ELECTROLUX ELS 7800 B',
            // 'PLAQUETA (MC-L13) DREAN CONCEPT 5.05 V1 PROGRAMABLE (COMUN O EEE)',
            // 'PLAQUETA (MC-L19) EWT 07A/09A/22A/24A (8 BOT.)',
            // 'PLAQUETA (MC-L23) GAFA FULL 6000/6100/7000 /ELECTROLUX ELAV 8450',
            // 'PLAQUETA (MC-L22) CONSUL CWR600 / EWD07A / EWD22A / CWD22 / CWD07',
            // 'CRISTAL DE PUERTA DREAN BLUE VIDRIO (OFERTA)',
            // 'AISLACION NEGRA  1/2 (13X9) COOLTECH (CO-F13) (PRECIO X METRO)',
            // 'AISLACION NEGRA 5/8 (16X6) COOLTECH (CO-D16) (PRECIO X METRO)',
            // 'CAÑO DE COBRE X ROLLO 5/16',
            // 'CAÑO DE COBRE X ROLLO 1/4',
            // 'CAÑO DE COBRE X ROLLO 3/8',
            // 'CAÑO DE COBRE X ROLLO 1/2',
            // 'FILTROS C/CHICOTE 15GR. (IMP.)',
            // 'CAPACITOR 35 MF 440 (CA-440-35)',
            // 'CAPACITOR 30 MF 440 (CA-440-30)',
            // 'CAPACITOR 50 MF 440 (CA-440-50)',
            // 'CAPACITOR 60 MF 440 (CA-440-60)',
            // 'CAPACITOR (CUADRADO) 1,5 MF 400V (CBB61)',
            // 'CAPACITOR (CUADRADO) 2.5 MF 400V (CBB61)',
            // 'CAPACITOR (CUADRADO) 3,0 MF 400V (CBB61)',
            // 'JUEGO DE MANGUERA 36" (R12) (GGT-336)',
            // 'MOTOCOMPRESOR HUAYI 1/4',
            // 'MOTOCOMPRESOR HUAYI 1/5',
            // 'MOTOCOMPRESOR HUAYI 1/3',
            // 'GAS REFRIGERANTE 134 (13.6 KG) BEON',
            // 'VARILLA DE PLATA CHATA PARA SOLDAR (S0%-h)',
            // 'TURBINA AIRE VENTANA 180x85MM',
            // 'PLAQUETA (MC-L23-1) GAFA 6000/6100/7000 1 CONECTOR 12 PINES',
            // 'PLAQUETA (MC-L18) AWR 680/682/683 (7 BOT.)',
            // 'GARANTIA (BL) CAPACIMETRO (BL-001)',
            // 'SERIGRAFIA LAVARROPAS DREAN CONCEPT 5.05 V1',
        ];

        foreach ($articles_name as $article_name) {
            $article = Article::where('user_id', 121)
                                ->where('name', $article_name)
                                ->first();

            if (!is_null($article)) {

                echo '<br>';
                echo '<br>';
                echo $article->name;
                echo '<br>';

                $stock_movements = StockMovement::where('article_id', $article->id)
                                                ->whereDate('created_at', '>=', Carbon::today()->subMonth())
                                                ->orderBy('created_at', 'ASC')
                                                ->get();


                foreach ($stock_movements as $stock_movement) {


                    echo '<br>';
                    echo '---------------';
                    echo '<br>';

                    echo 'Tiene que ser menor que '.$stock_movement->created_at.'. Id: '.$stock_movement->id;
                    echo '<br>';
                    
                    $anterior = StockMovement::where('article_id', $article->id)
                                                ->where('id', '<', $stock_movement->id)
                                                ->orderBy('id', 'DESC')
                                                ->first();
                    if (!is_null($anterior)) {

                        $stock_resultante = (float)$anterior->stock_resultante + (float)$stock_movement->amount;
                        echo '<br>';

                        echo 'fecha: '.$stock_movement->created_at->format('d/m/y');
                        echo '<br>';

                        echo 'amount: '.(float)$stock_movement->amount;
                        echo '<br>';

                        echo 'anterior stock_resultante: '.(float)$anterior->stock_resultante;
                        echo '<br>';

                        echo 'Sumando '.(float)$anterior->stock_resultante.' + '.(float)$stock_movement->amount.' = '.$stock_resultante;
                        echo '<br>';

                        $stock_movement->stock_resultante = $stock_resultante;
                        $stock_movement->save();
                    } else {

                        echo 'No habia anterior para '.$article_name;
                        echo '<br>';
                    }
                }
            } else {
                echo 'No habia article '.$article_name;
                echo '<br>';
            }

        }

        echo 'Termino';
    }

    function check_totales_diferentes($company_name) {

        $user = User::where('company_name', $company_name)
                        ->first();

        $sales = Sale::where('user_id', $user->id)
                        ->orderBy('created_at', 'ASC')
                        ->get();

        $delta = 0.00001;

        foreach ($sales as $sale) {
            
            $total_helper = (int)SaleHelper::getTotalSale($sale);
            $total_sale = (int)$sale->total;

            // Calcula la diferencia absoluta
            $diferencia = abs($total_helper - $total_sale);
            
            if ($diferencia > 3) {
                echo 'Venta N° '.$sale->num.' <br>';
                echo 'Total: '.$sale->total.' <br>';
                echo 'Total helper: '.$total_helper;
                echo '<br>';
                echo 'Descuento: '.$sale->descuento;
                echo '<br>';
                echo '<br>';
                echo '<br>';
            }
        }
        echo 'Termino';
    }

    function check_depositos($company_name) {

        $user = User::where('company_name', $company_name)
                        ->first();

        $articles = Article::where('user_id', $user->id)
                            ->orderBy('created_at', 'ASC')
                            ->get();

        foreach ($articles as $article) {
            
            if (count($article->addresses) >= 1) {

                $total_depositos = 0;

                foreach ($article->addresses as $address) {
                    
                    if (!is_null($address->pivot->amount)) {

                        $total_depositos += $address->pivot->amount;
                    }
                }

                if ($total_depositos != $article->stock) {

                    echo $article->name;
                    echo '<br>';
                    echo 'Id: '.$article->id;
                    echo '<br>';
                    echo 'Suma depositos: '.$total_depositos;
                    echo '<br>';
                    echo 'Stock: '.$article->stock;
                    echo '<br>';

                    $article->stock = $total_depositos;
                    $article->timestamps = false;
                    $article->save();

                    echo 'Se actualizo';
                    echo '<br>';
                    echo '<br>';
                }
            }
        }

        echo 'Termino';

    }

    function set_provider_orders_totales($company_name) {

        $user = User::where('company_name', $company_name)
                        ->first();

        $provider_orders = ProviderOrder::where('user_id', $user->id)
                                        ->orderBy('created_at', 'ASC')
                                        ->get();

        foreach ($provider_orders as $provider_order) {

            $helper = new NewProviderOrderHelper($provider_order, $provider_order->articles->toArray(), true);
            $helper->procesar_pedido();

            echo 'Se proceso pedido N° '.$provider_order->num.' de la fecha '.$provider_order->created_at->format('d/m/Y');
            echo '<br>';
            
        }
        echo '<br>';
        echo 'Termino';
    }

    function check_movimientos_de_stock($company_name) {

        $user = User::where('company_name', $company_name)
                        ->first();

        $movements = StockMovement::where('user_id', $user->id)
                                ->whereRaw('CAST(stock_resultante AS DECIMAL(10, 2)) != CAST(observations AS DECIMAL(10, 2))')
                                ->whereNotNull('observations')
                                ->where('observations', '!=', 'Se seteo stock resultante con el stock actual')
                                ->orderBy('created_at', 'ASC')
                                ->get();

        $results = [];
        
        foreach ($movements as $movement) {

            if (!isset($results[$movement->article_id])) {
                $results[$movement->article_id]['stock_movement'] = $movement;
                $results[$movement->article_id]['cantidad'] = 1;
            }  else {
                $results[$movement->article_id]['cantidad']++;
            }
        }

        foreach ($results as $key => $stock_movement) {

            $movement = $stock_movement['stock_movement'];
            if ($movement->article){
                echo $movement->article->name.' en la fecha '.$movement->created_at->format('d/m/Y');
                echo '<br>';
                echo 'cantidad: '.$stock_movement['cantidad'];
                echo '<br>';
                // $this->recalcular_stock($movement);
                echo '<br>';
                echo '<br>';
            } 
        }
        echo 'Termino';
    }

    function recalcular_stock($stock_movement) {

        $stock_movements_posteriores = StockMovement::where('article_id', $stock_movement->article_id)
                                                ->where('id', '>=', $stock_movement->id)
                                                ->orderBy('created_at', 'ASC')
                                                ->get();

        foreach ($stock_movements_posteriores as $stock_movement_posterior) {
            $stock_movement_posterior->observations = $stock_movement_posterior->stock_resultante;
            $stock_movement_posterior->save();
        }

        $stock_actual = $stock_movements_posteriores[count($stock_movements_posteriores)-1]->stock_resultante;

        $article = Article::find($stock_movement->article_id);
        $article->stock = $stock_actual;
        $article->timestamps = false;
        $article->save();

        echo 'Se va a poner el stock de '.$stock_actual;
        echo '<br>';
    }

    function restart_stock_nota_credito($id) {
        $nota_credito = CurrentAcount::find($id);

        foreach ($nota_credito->articles as $article) {
            
            $nuevo_stock = $article->pivot->amount * 2;

            echo $article->name.' Se le van a sumar '.$nuevo_stock.' al stock <br>';
            echo '<br>';

            $ct = new StockMovementController();
            $request = new \Illuminate\Http\Request();
            
            $request->model_id = $article->id;
            $request->from_address_id = null;
            $request->to_address_id = null;
            $request->amount = (float)$nuevo_stock;
            $request->sale_id = null;
            $request->concepto = 'Correcion Nota credito';
            $request->observations = '.';

            $ct->store($request, false, $nota_credito->user, $nota_credito->user_id);


        }
        echo 'Termino';
    }

    function nota_de_credito_afip($sale_id) {

        $sale = Sale::find($sale_id);

        if (is_null($sale)) {
            echo 'No hay venta <br>';
        } else {

            SaleHelper::createNotaCreditoFromDestroy($sale);
        }

        echo 'Listo';
    }

    function corregir_cuit($user_id) {
        $clients = Client::where('user_id', $user_id)
                            ->get();

        foreach ($clients as $client) {
            
            if (!is_null($client->cuit) 
                && (
                    str_contains($client->cuit, '-')
                    || str_contains($client->cuit, '_')
                    || str_contains($client->cuit, ' ')
                    || str_contains($client->cuit, '.')
                )
            ) {

                echo 'Cliente '.$client->name.'<br>';
                echo 'CUIT '.$client->cuit.'<br>';

                $nuevo_cuit = str_replace('-', '', $client->cuit);
                $nuevo_cuit = str_replace('_', '', $nuevo_cuit);
                $nuevo_cuit = str_replace(' ', '', $nuevo_cuit);
                $nuevo_cuit = str_replace('.', '', $nuevo_cuit);

                echo 'CUIT NUEVO '.$nuevo_cuit.'<br>';
                $client->cuit = $nuevo_cuit;
                $client->timestamps = false;
                $client->save();
                echo 'ACTUALIZADO '.$nuevo_cuit.'<br>';
                echo '<br>';
                echo '<br>';
            }
        }
    }

    function check_movimientos_pack() {
        $stock_movements = StockMovement::where('user_id', 600)
                                        ->whereDate('created_at', '>=', Carbon::today()->subDay())
                                        ->orderBy('created_at', 'ASC')
                                        ->get();

        foreach ($stock_movements as $stock_movement) {
            
            if ($stock_movement->stock_resultante != $stock_movement->observations) {

                echo '<br>';
                echo $stock_movement->article->name.'<br>';
                echo $stock_movement->article->id.'<br>';
                echo $stock_movement->concepto.'<br>';
                echo '<br>';
            }
        }
        echo 'Termino';
    }

    function set_sales_price_type_id() {
        $sales = Sale::where('user_id', 121)
                        ->whereDate('created_at', '>=', Carbon::now()->subMonths(2))
                        ->orderBy('created_at', 'ASC')
                        ->get();

        foreach ($sales as $sale) {
            
            if (is_null($sale->price_type_id)) {

                if (!is_null($sale->client) && !is_null($sale->client->price_type_id)) {

                    $sale->price_type_id = $sale->client->price_type_id;
                    $sale->timestamps = false;
                    $sale->save();

                    echo 'Venta N° '.$sale->num.' <br>';
                    echo 'Se puso price_type_id en '.$sale->price_type_id;
                    echo '<br>';
                }
            }
        }

        echo 'Termino';
    }

    function set_iva_debito($company_name) {

        $user = User::where('company_name', $company_name)
                        ->first();

        $sales = Sale::where('user_id', $user->id)
                    ->whereHas('afip_ticket')
                    ->orderBy('created_at', 'ASC')
                    ->get();

        foreach ($sales as $sale) {
            
            $afip_ticket = $sale->afip_ticket;
            
            if (!is_null($afip_ticket)) {

                if (is_null($afip_ticket->importe_iva)) {

                    $afip_helper = new AfipHelper($sale);
                    $importes = $afip_helper->getImportes();

                    $afip_ticket->importe_iva = $importes['iva'];
                    $afip_ticket->save();

                    echo 'Se actualizo venta N° '.$sale->num.'. Afip ticket con total de '.$afip_ticket->importe_total.'. Se le puso total iva de '.$importes['iva'].' <br>';
                    echo ' <br>';
                }
            }
        }
        
        echo 'Termino';

    }

    function recetas_con_insumos_repetidos() {
        $articulos_repetidos = DB::table('article_recipe')
                                ->select('recipe_id', 'article_id', DB::raw('COUNT(*) as article_count'))
                                ->groupBy('recipe_id', 'article_id')
                                ->having('article_count', '>', 1)
                                ->get();

        foreach ($articulos_repetidos as $repetido) {
            $article = Article::find($repetido->article_id);
            $recipe = Recipe::find($repetido->recipe_id);

            echo "Recipe ID: " . $repetido->recipe_id . " tiene el articulo id: " . $repetido->article_id . " repetido " . $repetido->article_count . " vences.";
            echo "<br>";
            if (!is_null($article)) {
                echo 'Nombre: '.$article->name;
                echo "<br>";
            } else {
                echo 'Articulo eliminado';
                echo "<br>";
            }

            if (!is_null($recipe)) {
                echo 'Articulo receta: '.$recipe->article->name;
                echo "<br>";
            } else {
                echo 'Receta eliminada';
                echo "<br>";
            }
            echo "<br>";
        }
        echo 'termino';
    }

    function check_totales_de_pagos($company_name) {

        $user = User::where('company_name', $company_name)
                        ->first();

        $pagos = CurrentAcount::where('user_id', $user->id)
                                ->where('status', 'pago_from_client')
                                ->whereNotNull('haber')
                                ->whereDate('created_at', '>=', Carbon::today()->subMonth()->startOfMonth())
                                ->orderBy('created_at', 'ASC')
                                ->get();

        foreach ($pagos as $pago) {
            
            $suma_payment_methods = 0;

            foreach ($pago->current_acount_payment_methods as $payment_method) {
                
                $suma_payment_methods += (float)$payment_method->pivot->amount;
            }

            if (count($pago->current_acount_payment_methods) >= 1 
                && $suma_payment_methods != $pago->haber) {

                echo 'Cliente: '.$pago->client->name.'<br>';
                echo 'Fecha: '.$pago->created_at->format('d/m/y').'<br>';
                echo $pago->detalle.' tiene haber de '.Numbers::price($pago->haber).' y suma_payment_methods: '.Numbers::price($suma_payment_methods).' <br>';
            }
        }
        echo 'Termino'; 

    }

    function set_terminada_at() {
        Log::info(Carbon::parse('2024/09/01'). ' Arranco');
        SetSalesTerminadaAtJob::dispatch();
        echo 'Se despacho SetSalesTerminadaAtJob';
    }

    function corregir_stock_excel() {
        $articles = Article::where('provider_id', 427) //Bellini
                            ->orWhere('provider_id', 390) //Bellini caños
                            ->orWhere('provider_id', 1096) //BELLINI CAÑOS
                            
                            ->get();

        foreach ($articles as $article) {
            
            $last_stock_movement = StockMovement::where('article_id', $article->id)
                                            ->orderBy('created_at', 'DESC')
                                            ->first();

            if (!is_null($last_stock_movement)) {

                if ($last_stock_movement->concepto == 'Importacion Excel') {

                    $amount = (float)$last_stock_movement->amount;
                    

                    $article_stock = (float)$article->stock;
                    
                    if ($article_stock == 0) {

                        echo $article->name.' - '.$article->provider_code.' </br>';

                        if ($amount < 0) {

                            $stock_para_sumar = abs($amount); 

                            $nuevo_stock = (float)$article->stock + $stock_para_sumar;

                            echo 'Sumando '.$stock_para_sumar.' </br>';
                            echo 'Queda en '.$nuevo_stock.' <br>';

                        } else if ($amount > 0) {

                            echo 'Mayor a 0 = '.$amount.' </br>';

                            $nuevo_stock = (float)$article->stock - $amount;

                            echo 'Sumando '.$amount.' </br>';
                            echo 'Queda en '.$nuevo_stock.' <br>';
                        } 

                        if ($amount != 0) {
                            
                            $article->stock = $nuevo_stock;
                            $article->timestamps = false;
                            $article->save();

                            $last_stock_movement->delete();
                        }

                        echo '----------- </br>';
                    }
                }
            }
        }
    }

    function corregir_stock_excel_en_0() {
        $articles = Article::where('provider_id', 427) //Bellini
                            ->orWhere('provider_id', 390) //Bellini caños
                            ->orWhere('provider_id', 1096) //BELLINI CAÑOS

                            ->get();

        foreach ($articles as $article) {
            
            $last_stock_movements = StockMovement::where('article_id', $article->id)
                                            ->where('created_at', '>=', Carbon::today()->subDays(1))
                                            ->orderBy('created_at', 'DESC')
                                            ->get();

            foreach ($last_stock_movements as $last_stock_movement) {


                if ($last_stock_movement->concepto == 'Importacion Excel') {

                    $amount = (float)$last_stock_movement->amount;

                    if ((float)$amount != 0) {
                        
                        $article_stock = (float)$article->stock;
                        
                        // if ($article_stock == 0) {

                            echo $article->name.' - '.$article->provider_code.' </br>';

                            echo 'Stock: '.$article->stock.' </br>';

                            if ($amount < 0) {

                                $stock_para_sumar = abs($amount); 

                                $nuevo_stock = (float)$article->stock + $stock_para_sumar;

                                echo 'Sumando '.$stock_para_sumar.' </br>';
                                echo 'Queda en '.$nuevo_stock.' <br>';

                            } else if ($amount > 0) {

                                echo 'Mayor a 0 = '.$amount.' </br>';

                                $nuevo_stock = (float)$article->stock - $amount;

                                echo 'Sumando '.$amount.' </br>';
                                echo 'Queda en '.$nuevo_stock.' <br>';
                            } 

                            if ($amount != 0) {
                                
                                $article->stock = $nuevo_stock;
                                $article->timestamps = false;
                                $article->save();

                                $last_stock_movement->delete();
                            }

                            echo '----------- </br>';
                        // }
                    }

                }
            }

        }
    }

    function set_final_prices($user_id) {
        $articles = Article::where('user_id', $user_id)
                            ->orderBy('id', 'ASC')
                            ->get();

        $user = User::find($user_id);

        foreach ($articles as $article) {
            echo 'Se seteo el precio de '.$article->name.'. Paso de '.$article->final_price.' </br>';
            ArticleHelper::setFinalPrice($article, $user_id, $user, $user_id);
            echo 'A '.$article->final_price.' </br>';
            echo '---------- </br>';
        }
        echo 'Listo';
    }

    function inventory_linkage_articulos_eliminados($user_id) {

        $articles = Article::where('user_id', $user_id)
                            ->whereNotNull('provider_article_id')
                            ->orderBy('created_at', 'ASC')
                            ->get();

        $repetidos = [];

        foreach ($articles as $article) {


            $provider_article = Article::find($article->provider_article_id);

            if (is_null($provider_article)) {

                echo '<br>';
                echo 'NO ESTA '.$article->name.' codigo: '.$article->bar_code.', con costo '.Numbers::price($article->cost).' creado el '.$article->created_at->format('d/m/Y'). ' <br>';

                $article->delete();

                echo 'Eliminado <br>';
                echo '<br>';

                // echo $article->name.' con costo '.$article->cost.' no esta <br>';
            }
            
            // if (!is_null($article->bar_code)) {

            //     if (!$this->esta_en_array($repetidos, $article->bar_code)) {

            //         $repetido = Article::where('bar_code', $article->bar_code)
            //                             ->where('user_id', $user_id)
            //                             ->where('id', '!=', $article->id)
            //                             ->first();

            //         if (!is_null($repetido)) {

            //             $repetidos[] = $repetido;

            //             echo ' <br>';
            //             echo 'Repetido: <br>';
            //             echo $article->name.' codigo: '.$article->bar_code.', con costo '.Numbers::price($article->cost).' creado el '.$article->created_at->format('d/m/Y'). ' <br>';
            //             echo $repetido->name.' codigo: '.$repetido->bar_code.', con costo '.Numbers::price($repetido->cost).' creado el '.$repetido->created_at->format('d/m/Y'). ' <br>';



            //             // $provider_article = Article::find($article->provider_article_id);

            //             // if (is_null($provider_article)) {

            //             //     echo $article->name.' con costo '.$article->cost.' no esta <br>';
            //             // }


            //             // $provider_article = Article::find($repetido->provider_article_id);

            //             // if (is_null($provider_article)) {

            //             //     echo $repetido->name.' con costo '.$repetido->cost.' no esta <br>';
            //             // }

            //             // echo ' <br> ******************** <br>';
            //             // echo ' <br>';
            //         }

            //         // if ($repetido->created_at->lt())
            //     }

            // }
        }
    }

    function esta_en_array($array, $propertyValue) {
        $exists = !empty(array_filter($array, function ($item) use ($propertyValue) {
            return $item->bar_code == $propertyValue;
        }));

        return $exists;
    }

    function chequear_articulos_eliminados_en_ventas($dias_atras) {
        $sales = Sale::where('user_id', 121)
                        ->where('confirmed', 1)
                        ->where('num', '>', 59560)
                        ->whereDate('created_at', '>=', Carbon::today()->subdays($dias_atras))
                        ->orderBy('created_at', 'ASC')
                        ->get();

        foreach ($sales as $sale) {
            $sale_modifications = SaleModification::where('sale_id', $sale->id)
                                                ->orderBy('created_at', 'ASC')
                                                ->get();
            foreach ($sale_modifications as $sale_modification) {
                if ($sale_modification->estado_antes_de_actualizar == 'ninguno'
                    || $sale_modification->estado_antes_de_actualizar == 'Confirmada') {
                    if (count($sale_modification->articulos_antes_de_actualizar) != count($sale_modification->articulos_despues_de_actualizar)) {
                        echo 'La venta N° '.$sale->num.' del '.$sale->created_at->format('d/m/Y').' tenia '.count($sale_modification->articulos_antes_de_actualizar).' y despues '.count($sale_modification->articulos_despues_de_actualizar).' <br>';

                        foreach ($sale_modification->articulos_antes_de_actualizar as $articulo_antes_de_actualizar) {
                            
                            $eliminado = true;

                            foreach ($sale_modification->articulos_despues_de_actualizar as $articulo_despues_de_actualizar) {
                                
                                if ($articulo_despues_de_actualizar->id == $articulo_antes_de_actualizar->id) {
                                    $eliminado = false;
                                }

                            }

                            if ($eliminado) {
                                echo '-> El articulo '.$articulo_antes_de_actualizar->name.' ya no esta mas <br>';


                                $ct = new StockMovementController();
                                $request = new \Illuminate\Http\Request();
                                
                                $request->model_id = $articulo_antes_de_actualizar->id;
                                $request->from_address_id = null;
                                $request->to_address_id = null;
                                $request->amount = (float)$articulo_antes_de_actualizar->pivot->amount;
                                $request->sale_id = $sale->id;
                                $request->concepto = 'Se elimino de la venta '.$sale->num;
                                $request->observations = '.';

                                $ct->store($request, false, $sale->user, $sale->user_id);

                                echo 'Se guardo movimiento de stock <br>';
                            }

                        }
                    }
                }
            }
        }
        echo 'Termino';
    }

    function cuentas_corrientes_con_toda_la_info($model_id = 8094) {
        $models = CurrentAcount::where('client_id', $model_id)
                                ->with('pagado_por')
                                ->orderBy('created_at', 'ASC')
                                ->get();

        
        foreach ($models as $model) {
            echo '-> Cuenta corriente: '.$model->detalle.' | debe: '.$model->debe.' | haber: '.$model->haber.' | saldo: '.$model->saldo.' <br>';
            if (!is_null($model->debe)) {
                echo 'Relacion Pagado por: <br>';
                foreach ($model->pagado_por as $pago) {

                    echo 'Pago: '.$pago->detalle.   'Total del Pago: '.$pago->total_pago.'Fondos iniciales'.$pago->fondos_iniciales. 'Remanente a cubrir: '.$pago->a_cubrir.   'Aporte del Pago: '.$pago->pagado.'Fondos resultantes: '.$pago->nuevos_fondos.'Nuevo remanente: '.$pago->remantente  .'Fecha: '.$pago->created_at.'<br>';

                }
            }

            if (!is_null($model->haber)) {
                echo 'Relacion Pagando a: <br>';
                foreach ($model->pagando_a as $deuda) {

                    echo 'Deuda: '.$deuda->detalle.   'Total del Pago: '.$deuda->total_pago.'Fondos iniciales'.$deuda->fondos_iniciales. 'Remanente a cubrir: '.$deuda->a_cubrir.   'Aporte del Pago: '.$deuda->pagado.'Fondos resultantes: '.$deuda->nuevos_fondos.'Nuevo remanente: '.$deuda->remantente  .'Fecha: '.$deuda->created_at.'<br>';

                }
            }
        }

    }

    function diferencia_entre_ventas() {
        $ventas_de_antes = [];
        $ventas_de_ahora = [];
        $ventas_repetidas = [];

        foreach ($ventas_de_antes as $venta_de_antes) {

            if (isset($ventas_de_ahora[$venta_de_antes->id])) {
                $ventas_repetidas[] = $venta_de_ahora;
            } else {

            }
        }
    }

    function num_receipt_repetidos() {
        $repeatedReceipts = CurrentAcount::select('num_receipt')
                                ->groupBy('num_receipt')
                                ->havingRaw('COUNT(*) > 1')
                                ->pluck('num_receipt');

        $repeated = CurrentAcount::whereIn('num_receipt', $repeatedReceipts)->get();

        foreach ($repeated as $current_acount) {
            echo $current_acount->detalle.' <br>';
            echo 'Num recepit: '.$current_acount->num_receipt.' <br>';
            if (!is_null($current_acount->client)) {
                echo 'Cliente: '.$current_acount->client->name.' <br>';
            }
            if (!is_null($current_acount->user)) {
                echo 'Cliente: '.$current_acount->user->name.' <br>';
            }
            echo '------------------------ <br>';
        }

    }

    function current_acount_duplicadas() {
        
        // Subconsulta para obtener los valores duplicados de saldo y client_id
        $duplicatedEntries = CurrentAcount::select('saldo', 'client_id', 'created_at')
                ->groupBy('saldo', 'client_id', 'created_at')
                ->havingRaw('COUNT(*) > 1')
                ->get(['saldo', 'client_id', 'created_at']);

        // Convertir los resultados de la subconsulta en arrays de saldo, client_id y created_at
        $saldos = $duplicatedEntries->pluck('saldo')->toArray();
        $clientIds = $duplicatedEntries->pluck('client_id')->toArray();
        $createdAts = $duplicatedEntries->pluck('created_at')->toArray();

        // Consulta principal para obtener los modelos CurrentAcount con las combinaciones duplicadas
        $currentAccounts = CurrentAcount::where(function ($query) use ($saldos, $clientIds, $createdAts) {
            foreach ($saldos as $index => $saldo) {
                $query->orWhere(function ($query) use ($saldo, $clientIds, $createdAts, $index) {
                    $query->where('saldo', $saldo)
                        ->where('client_id', $clientIds[$index])
                        ->where('created_at', $createdAts[$index]);
                });
            }
        })->get();

        // Agrupar los resultados por balance, client_id y created_at
        $groupedAccounts = [];
        foreach ($currentAccounts as $account) {
            $key = $account->balance . '|' . $account->client_id . '|' . $account->created_at;
            if (!isset($groupedAccounts[$key])) {
                $groupedAccounts[$key] = [];
            }
            $groupedAccounts[$key][] = $account;
        }

        // Convertir los resultados agrupados en arrays separados
        $grupos = array_values($groupedAccounts);


        foreach ($grupos as $grupo) {

            echo 'Grupo: </br>';
            if (isset($grupo[0]->client) && $grupo[0]->client->user_id == 121) {
                echo 'cliente: '.$grupo[0]->client->name.' </br>';

                if (isset($grupo[1])) {
                    $grupo[1]->delete();
                    echo 'se elimino </br>';

                    // CurrentAcountHelper::checkSaldos('client', $grupo[0]->client->id);
                    // echo 'se chequearon saldos </br>';
                    // CurrentAcountHelper::checkPagos('client', $grupo[0]->client->id, true);
                    // echo 'se chequearon pagos </br>';
                }
            }


            // foreach ($grupo as $current_acount) {
            //     echo $current_acount->detalle.'. Id '.$current_acount->id.' </br>';

            //     echo 'Fecha: '.$current_acount->created_at->format('d/m/Y H:i:s').' <br>';

            //     if (!is_null($current_acount->client)) {
            //         echo 'EL cliente es: '.$current_acount->client->name.' </br>';
            //     }

            //     if (!is_null($current_acount->user)) {
            //         echo 'y el usuario: '.$current_acount->user->name.' </br>';
            //     } else {
            //         echo 'No tenia user <br>';
            //     }

            // }
            echo '------------------ </br>';
        }

        echo 'Termino';
    }

    function current_acounts_repetidas() {
        $sales = Sale::where('user_id', 121)
                        ->whereHas('current_acounts', function ($query) {
                            $query->where('status', '!=', 'nota_credito');
                        }, '>', 1)
                        ->get();

        foreach ($sales as $sale) {
            echo 'La venta N° '.$sale->num.' id '.$sale->id.' tiene '.count($sale->current_acounts).' </br>';
            if (!is_null($sale->client)) {
                echo 'EL cliente es: '.$sale->client->name.' </br>';
            }
            echo 'y el usuario: '.$sale->user->name.' </br>';

            echo '-> Info: <br>';
            foreach ($sale->current_acounts as $current_acount) {
                echo $current_acount->detalle.'. Debe: '.Numbers::price($current_acount->debe).'. Fecha: '.$current_acount->created_at->format('d/m/Y H:i:s').' <br>';
            }

            echo '------------------ </br>';
        }
        echo 'termino';
    }

    function check_pagos($client_id) {
        
        $client = Client::find($client_id);

        if (!is_null($client)) {
            echo 'Chequeando pagos </br>';
            CurrentAcountHelper::checkPagos('client', $client->id, true);
        } else {
            echo 'NO habia cliente';
        }
        echo 'termino';


    }

    function check_unidades_en_0() {
        $sales = Sale::where('confirmed', 1)
                        ->whereDate('created_at', '>=', Carbon::today()->subDays(5))
                        ->where('user_id', 121)
                        ->get();

        $venta_con_articulos_en_0 = [];

        foreach ($sales as $sale) {
            foreach ($sale->articles as $article) {
                if ($article->pivot->amount == 0) {
                    if (!$this->containsObject($venta_con_articulos_en_0, $sale)) {
                        $venta_con_articulos_en_0[] = $sale;
                    }
                }
            }
        }

        dd($venta_con_articulos_en_0);
    }

    function containsObject($array, $object) {
        foreach ($array as $item) {
            if ($item->is($object)) {
                return true;
            }
        }
        return false;
    }

    function borrar_stock_movements_de_production_movement($article_id) {
        $article_recipe = Article::find($article_id);

        foreach ($article_recipe->recipe->articles as $insumo) {
            echo 'Insumo '.$insumo->name.' </br>';
            $stock_movements = StockMovement::where('article_id', $insumo->id)
                                            ->whereDate('created_at', Carbon::now())
                                            ->orderBy('created_at', 'ASC')
                                            ->get();

            $vuelta = 1;

            foreach ($stock_movements as $stock_movement) {
                if ($vuelta == 1) {

                    if (preg_match('/\((\d+)\)$/', $stock_movement->observations, $matches)) {
                        $numero = $matches[1];
                        echo "El número es: " . $numero.' </br>';
                        $insumo->stock = (float)$numero;
                        $insumo->save();
                    } 

                } else {
                    $stock_movement->delete();
                }

                $vuelta++; 
            }
        }
        echo 'termino';
    }

    function check_deleted_articles_recipes() {
        $recipes = Recipe::where('user_id', 138)
                            ->get();
        foreach ($recipes as $recipe) {
            if (is_null($recipe->article)) {
                $recipe->delete();
                echo 'Se elimino la receta N° '.$recipe->num.' </br>';
            }
        }
        echo 'Listo';
    }

    function recalculate_stock_resultante_from_user($company_name) {

        $user = User::where('company_name', $company_name)
                        ->first();

        ProcessSetStockResultante::dispatch($user);
    }

    function recalculate_stock_resultante($article_id) {

        if (!is_object($article_id)) {
            $article = Article::find($article_id);
        } else {
            $article = $article_id;
        }

        $stock_movements = StockMovement::where('article_id', $article->id)
                                        ->orderBy('created_at', 'ASC')
                                        ->get();

        $stock_resultante = (float)$stock_movements[0]->stock_resultante;
        
        echo 'Articulo: '.$article->name.' </br>';

        echo 'Cantidad de primero stock_movement: '.$stock_resultante.' </br>';

        $index = 0;
        foreach ($stock_movements as $stock_movement) {
            
            if ($index > 0) {
                if (!is_null($stock_movement->sale_id)) {
                    $stock_resultante -= (float)$stock_movement->amount;
                    echo 'Se resto (-)'.$stock_movement->amount.' por: '.$stock_movement->concepto.' y quedo en: '. $stock_resultante .' </br>';
                } else {
                    $stock_resultante += (float)$stock_movement->amount;
                    echo 'Se sumo (+)'.$stock_movement->amount.' por: '.$stock_movement->concepto.' y quedo en: '. $stock_resultante .' </br>';
                }
            }
            $index++;
        }

        echo 'El articulo '.$article->name.' tendria que tener '.$stock_resultante.' y tiene un stock actual de '.$article->stock.' </br>';

        if ($stock_resultante == $article->stock) {
            echo 'SI </br>';
        } else {
            echo '------> NO <--------  </br>';
        }


    }

    function check_inventory_linkages($company_name) {

        $user = User::where('company_name', $company_name)
                        ->first();

        ProcessCheckInventoryLinkages::dispatch($user);

        echo 'Se despacho';
    }

    function check_stock() {
        $article_id = 93016;
        $article = Article::find($article_id);

        $primer_movimiento_de_stock = StockMovement::where('article_id', $article_id)
                                                    ->orderBy('created_at', 'ASC')
                                                    ->first();

        if (!is_null($primer_movimiento_de_stock)) {
            echo 'Primer movimiento de stock amount: '.$primer_movimiento_de_stock->amount.'. Stock en ese momento: '.$primer_movimiento_de_stock->stock_resultante.' </br>';

            $stock_inicial = (float)$primer_movimiento_de_stock->stock_resultante;
            
            foreach ($article->sales as $sale) {
                if (!$sale->to_check && !$sale->checked) {
                    echo 'Restando '.$sale->pivot->amount.' de la venta N° '.$sale->num.' <br>';
                    $stock_inicial -= (float)$sale->pivot->amount;

                    if (!is_null($sale->pivot->returned_amount)) {
                        $stock_inicial += (float)$sale->pivot->returned_amount;
                        echo 'Devolviendo '.$sale->pivot->returned_amount.' <br>';
                    }

                    echo 'Quedo en '.$stock_inicial.' <br>';
                    echo '--------------- <br>';
                }
            }

            echo 'El stock deberia ser de: '.$stock_inicial.' <br>';
            echo 'Y es de: '.$article->stock.' <br>';
        }
    }

    function register_user($id, $name, $doc_number, $company_name, $iva_included, $extencions_id, $database = null) {

        $data = [
            'id'                    => $id,
            'name'                  => $name,
            'doc_number'            => $doc_number,
            'company_name'          => $company_name,
            'iva_included'          => $iva_included,
            'password'              => bcrypt('123'),
            'password'              => bcrypt('123'),
            'download_articles'     => 1,
            'base_de_datos'         => $database,
        ];

        $this->store_user($data, $extencions_id);
        echo 'Usuario creado en la base de datos principal </br>';


        // Config::set('database.connections.mysql.database', $database);
        // DB::purge('mysql');
        // DB::reconnect('mysql');

        // $this->store_user($data, $extencions_id);

        echo 'Usuario creado en la base de datos: '.$database;
    }

    function store_user($data, $extencions_id) {

        $user = User::create($data);

        $user->extencions()->attach(explode('-', $extencions_id));

        UserConfiguration::create([
            'current_acount_pagado_details'         => 'Saldado',
            'current_acount_pagandose_details'      => 'Recibo de pago',
            'iva_included'                          => 1,
            'limit_items_in_sale_per_page'          => null,
            'can_make_afip_tickets'                 => 1,
            'user_id'                               => $user->id,
        ]);
    }

    function set_cheques_user_id() {

        $cheques = CurrentAcountCurrentAcountPaymentMethod::where('current_acount_payment_method_id', 1)
                                                            ->orderBy('created_at', 'ASC')
                                                            ->get();

        foreach ($cheques as $cheque) {
            $current_acount = CurrentAcount::find($cheque->current_acount_id);
            $cheque->user_id = $current_acount->user_id;
            $cheque->save();
            echo 'Se le puso user_id '.$current_acount->user_id.' al cheque '.$cheque->bank.' </br>';
        }
    }
 
    function articulos_matias_categorias() {

        $sub_categorias_matias = SubCategory::where('user_id', 188)
                                        ->whereNull('provider_sub_category_id')
                                        ->get();

        foreach ($sub_categorias_matias as $sub_categoria_matias) {
            $articles = Article::where('user_id', 188)
                                ->whereNotNull('provider_article_id')
                                ->where('sub_category_id', $sub_categoria_matias->id)
                                ->get();
            foreach ($articles as $article) {
                $article->category_id = null;
                $article->sub_category_id = null;
                $article->timestamps = false;
                $article->save();
                echo 'Se actualizo '.$article->name.' </br>';
                // echo $article->category->name.' - '.$article->name.' </br>';
                // if (!is_null($article->sub_category)) {
                //     echo 'SUB CATEGORIA: '.$article->sub_category->name.' </br>';
                // }
            }
            // echo '--------------------------- </br>';
        }

        // echo 'Listo';

    }

    function article_performances() {
        for ($meses_atras=6; $meses_atras >= 1 ; $meses_atras--) { 
            $ct = new ArticlePerformanceController();
            $ct->setArticlesPerformance('hipermax', $meses_atras);
            echo 'Se hizo '.$meses_atras.' meses atras </br>';
        }
        echo 'Listo';
    }

    function articulos_eliminados_de_recipes() {
        $recipes = Recipe::where('user_id', 138)
                            ->orderBy('id', 'ASC')
                            ->get();
        foreach ($recipes as $recipe) {
            foreach ($recipe->articles as $article) {
                if (!is_null($article->deleted_at)) {
                    echo $article->name.' </br>';
                }
            }
        }

        echo 'Listo';
    }

    function quitar_articulos_eliminados_de_recipes() {
        $recipes = Recipe::where('user_id', 138)
                            ->orderBy('id', 'ASC')
                            ->get();
        foreach ($recipes as $recipe) {
            $costo_acutal = $recipe->article->cost;
            RecipeHelper::checkCostFromRecipe($recipe, $this, 138);
            $recipe->load('article');
            $cost_nuevo = $recipe->article->cost;
            if ($costo_acutal != $cost_nuevo) {
                echo 'Cambio el costo de '.$recipe->article->name.' de '.$costo_acutal.' a '.$cost_nuevo.' </br>';
            }
            echo 'Listo';
        }

    }

    function matias_articulos_sin_categoria() {
        $matias_articles = Article::where('user_id', 188)
                            ->orderBy('id', 'ASC')
                            ->whereNull('sub_category_id')
                            ->get();
        foreach ($matias_articles as $matias_article) {
            $oscar_article = Article::where('id', $matias_article->provider_article_id)
                                    ->first();
            if (!is_null($oscar_article)) {
                $helper = new InventoryLinkageHelper(null, $oscar_article->user_id);
                $helper->checkArticle($oscar_article);
                // $oscar_article->load();
                echo $matias_article->name.' se le puso la categoria </br>';
            }
        }
        echo 'Listo';
    }


    function set_matias_agotados() {
        $articles = Article::where('user_id', 188)
                            ->orderBy('id', 'ASC')
                            ->get();
        foreach ($articles as $article_matias) {
            $oscar_article = Article::find($article_matias->provider_article_id);

            if (!is_null($oscar_article) && $oscar_article->stock <= 0) {
                echo $oscar_article->name.' tiene '.$oscar_article->stock.' </br>';
                $article_matias->stock = 0;
                $article_matias->save();

                echo 'Se puso en 0 el '.$article_matias->name.' de Matias</br>';
            }

        }
        echo 'Listo';
    }

    function set_matias_imagenes() {
        $articles = Article::where('user_id', 188)
                            ->orderBy('id', 'ASC')
                            ->doesntHave('images')
                            ->get();
        foreach ($articles as $article_matias) {
            $oscar_article = Article::find($article_matias->provider_article_id);

            if (!is_null($oscar_article) && count($oscar_article->images) >= 1) {
                $client_article_image = Image::create([
                    env('IMAGE_URL_PROP_NAME', 'image_url')     => $oscar_article->images[0]->{env('IMAGE_URL_PROP_NAME', 'image_url')},
                    'imageable_id'                              => $article_matias->id,
                    'imageable_type'                            => 'article',
                ]);
                echo 'Se creo imagen para '.$article_matias->name.' </br>';
            }

        }
        echo 'Listo';
    }

    function set_matias_slug() {
        $articles = Article::where('user_id', 188)
                            ->orderBy('id', 'ASC')
                            ->get();
        foreach ($articles as $article_matias) {
            $oscar_article = Article::find($article_matias->provider_article_id);

            if (!is_null($oscar_article)) {
                $article_matias->slug = $oscar_article->slug;
                $article_matias->save();
                echo 'Se corrigio '.$article_matias->name.' </br>';
            }

        }
        echo 'Listo';
    }

    function poner_impresas_las_confirmadas() {
        Sale::where('confirmed', 1)
            ->update([
                'printed'   => 1,
            ]);
    }

    function corregir_articulos_matias() {
        $articles = Article::where('user_id', 188)
                            ->orderBy('id', 'ASC')
                            ->get();
        foreach ($articles as $article_matias) {
            $oscar_article = Article::find($article_matias->provider_article_id);

            $article_matias->cost = $oscar_article->final_price;
            $article_matias->save();
            ArticleHelper::setFinalPrice($article_matias, 188);
            echo 'Se corrigio '.$article_matias->name.' </br>';
        }
        echo 'Listo';
    }

    function check_pagos_repetidos() {
        $resultadosRepetidos = CurrentAcount::select('num')
                                    ->groupBy('num')
                                    ->where('user_id', 121)
                                    ->havingRaw('COUNT(num) > 1')
                                    ->get();
        foreach ($resultadosRepetidos as $current_acount) {
            echo 'Haber: '.$current_acount->haber.'. Num: '.$current_acount->num.'. created_at: '.$current_acount->created_at.' </br>';
        }
        // dd($resultadosRepetidos);
    }

    function check_article_addresses() {
        $articles = Article::where('user_id', 138)
                            ->whereHas('addresses')
                            ->get();

        foreach ($articles as $article) {
            $stock_addresses = 0;
            foreach ($article->addresses as $address) {
                $stock_addresses += $address->pivot->amount;
            }
            if ($article->stock != $stock_addresses) {
                echo 'No coincide el stock de '.$article->name.' </br>';
                foreach ($article->addresses as $address) {
                    echo 'Hay '.$address->pivot->amount.' en '.$address->street.' </br>';
                }
                echo 'Total en direcciones: '.$stock_addresses.' </br>';
                echo 'Stock global: '.$article->stock.' </br>';
                // echo '-------------------------------------------------------- </br>';

                $last_stock_movement = StockMovement::where('article_id', $article->id)
                                                    ->orderBy('created_at', 'DESC')
                                                    ->first();

                if (!is_null($last_stock_movement)) {
                    $stock_movement_address = null;
                    $otras_stock_movement_address = [];
                    foreach ($article->addresses as $address) {
                        if ($address->id == $last_stock_movement->to_address_id) {
                            $stock_movement_address = $address;
                        } else {
                            $otras_stock_movement_address[] = $address;
                        }
                    }

                    if (!is_null($stock_movement_address)) {
                        $suma_address_mas_stock_movement = $last_stock_movement->amount + $stock_movement_address->pivot->amount;
                        $suma_address_mas_stock_movement_original = $suma_address_mas_stock_movement;
                        
                        foreach ($otras_stock_movement_address as $otra_address) {
                            $suma_address_mas_stock_movement += $otra_address->pivot->amount;
                        }

                        if ($suma_address_mas_stock_movement == $article->stock) {
                            echo 'Da la suma para '.$article->name.' </br>';
                            // echo '-------------------------------------------------------- </br>';
                            

                            echo 'Ultimo movimiento de stock: '.$last_stock_movement->amount.' </br>';

                            $new_amount = $suma_address_mas_stock_movement_original;
                            echo 'Se actaulizo el stock de '.$stock_movement_address->street.' a '.$new_amount.' </br>';
                            // $article->addresses()->updateExistingPivot($stock_movement_address->id, [
                            //     'amount'    => $new_amount,
                            // ]);
                        }
                    }                
                }
                echo '-------------------------------------------------------- </br>';



            }
        }
    }

    function excel_ventas() {
        return Excel::download(new SalesExport, 'ventas.xlsx');
    }

    function quitar_depositos() {
        $articles = Article::where('user_id', 228)
                            ->whereHas('addresses')
                            ->get();
        echo 'Hay '.count($articles).' con depostios';
        foreach ($articles as $article) {
            echo $article->name.' tiene depositos </br>';
            $article->addresses()->detach();
        }

        $articles = Article::where('user_id', 228)
                            ->whereHas('addresses')
                            ->get();
        echo 'Ahora hay '.count($articles).' con depostios';
    }

    function set_sales_cost() {
        // $sales = Sale::where('id', 102571)
        //                 ->get();
        $sales = Sale::where('user_id', 2)
                        ->where('created_at', '>=', Carbon::today())
                        ->orderBy('created_at', 'ASC')
                        ->get();
        foreach ($sales as $sale) {
            echo 'Venta N° '.$sale->num. ' </br>';
            foreach ($sale->articles as $article) {
                if (is_null($article->pivot->cost)) {
                    $sale->articles()->updateExistingPivot($article->id, [
                        'cost'  => $article->cost,
                    ]);

                    echo 'Se seteo el costo de '.$article->name.' con '.$article->cost. ' </br>';
                }
            }
            echo '------------------- </br>';
        }
    }

    function articulos_faltantes() {
        require(base_path().'\app\Http\Controllers\Helpers\helper-info\articles_id_22_noviembre.php');
        echo 'Ahora no estan los siguientes id </br>';
        foreach ($articles_ids as $article_id) {
            $article = Article::find($article_id);
            if (is_null($article)) {
                echo $article_id.', </br>';
            }
        }
    }

    function articulos_que_no_estan() {
        require(base_path().'\app\Http\Controllers\Helpers\helper-info\articles_del_22_noviembre_que_no_estan.php');
        echo 'Los articulos del 15 de octubre que ahora no estan: '.count($articles_ids).' </br>';
        echo '----------------------------------- </br>';
        foreach ($articles_ids as $article_id) {
            $article = Article::where('id', $article_id)
                                ->withTrashed()
                                ->first();
            // echo '----------------------------------- </br>';
            // if (!is_null($article)) {
            if (!is_null($article) && is_null($article->deleted_at)) {
                // if (!is_null($article->deleted_at)) {
                //     echo 'ESTA ELIMINADO </br>';
                // }
                echo 'Codigo de barras: '.$article->bar_code.' </br>';
                echo 'Nombre: '.$article->name.' </br>';
                echo '----------------------------------- </br>';
            } 
        }
    }

    function articulosRepetidos($provider_id) {
        $user = User::find(228);
        $articles = Article::where('user_id', $user->id)  
                                ->where('provider_id', $provider_id)    
                                ->where('category_id', 245)    
                                ->get();
        $articulos_repetidos = [];
        foreach ($articles as $article) {
            if (array_key_exists($article->name, $articulos_repetidos)) {
                $articulos_repetidos[$article->name]++;
            } else {
                $articulos_repetidos[$article->name] = 1;
            }
        }
        foreach ($articulos_repetidos as $key => $value) {
            if ($value > 1) {
                echo('Hay '.$value.' de '.$key.' </br>');
            }
        }
    }

    function checkCartArticlesInsuficienteAmount($company_name) {
        $user = User::where('company_name', $company_name)
                        ->first();
        $articles = Article::where('user_id', $user->id)
                            ->get();

        foreach ($articles as $article) {
            CartArticleAmountInsificienteHelper::checkCartsAmounts($article);
        }
    }

    function rehacerFacturas() {
        $user = User::where('doc_number', '09876543')
                        ->first();


        $sales = Sale::where('created_at', '>=', '2023-10-01')
                        ->whereHas('afip_ticket')
                        ->orderBy('created_at', 'ASC')
                        ->where('user_id', $user->id)
                        ->get();

        // $sales = Sale::where('id', 89913)
        //                 ->get();

        foreach ($sales as $sale) {

            if ($sale->afip_ticket->importe_total == 0) {
                echo date_format($sale->created_at, 'd-m-y').' </br>';
                echo 'Venta id: '.$sale->id. ' </br>'; 
                echo 'Venta N°: '.$sale->num. ' </br>'; 
                echo 'Afip ticket id: '.$sale->afip_ticket->id. ' </br>'; 
                echo 'Punto venta: '.$sale->afip_ticket->punto_venta . ' </br>'; 
                echo 'N° ' .$sale->afip_ticket->cbte_numero . ' </br>'; 
                echo 'Importe: '.$sale->afip_ticket->importe_total .' </br>';
                echo '-------------------------------- </br>';

                $afip = new AfipWsController(['sale' => $sale]);
                $afip_ticket = $afip->init();

                // dd($afip_ticket);
            }

        }

    }

    function imagesWebpToJpg($company_name) {
        $user = User::where('company_name', $company_name)
                        ->first();

        $articles = Article::where('user_id', $user->id)
                            ->get();
        foreach ($articles as $article) {
            foreach ($article->images as $image) {
                $array = explode('/', $image->hosting_url); 
                $name = $array[count($array)-1];
                $name = explode('.', $name)[0];
                
                Log::info('image name: '.$name);

                $img = imagecreatefromwebp($image->hosting_url);
                
                imagejpeg($img, storage_path().'/app/public/'.$name.'.jpg', 100);
            }
        }
    }

    function getBuyerSinVincular($company_name) {
        $user = User::where('company_name', $company_name)
                        ->first();

        $buyers = Buyer::where('user_id', $user->id)
                        ->whereNull('comercio_city_client_id')
                        ->get();
        foreach ($buyers as $buyer) {
            echo $buyer->name.' N°'.$buyer->num.' </br>';
        }
    }

    function updateBetaImges() {
        $images = Image::where('hosting_url', 'LIKE', '%api-beta.comerciocity%')
                            ->get();
        foreach ($images as $image) {
            $new_url = 'https://api-empresa'.substr($image->hosting_url, 16);
            $image->hosting_url = $new_url;
            $image->save();
            echo 'new_url: '.$image->hosting_url.' </br>';
        }
    }

    function reemplazarProveedoresEliminados($company_name) {
        $user = User::where('company_name', $company_name)
                        ->first();
        $providers = Provider::where('user_id', $user->id)      
                                ->get();
        $proveedores_repetidos = [];
        foreach ($providers as $provider) {
            if (array_key_exists($provider->name, $proveedores_repetidos)) {
                $proveedores_repetidos[$provider->name]++;
            } else {
                $proveedores_repetidos[$provider->name] = 1;
            }
        }

        foreach ($proveedores_repetidos as $proveedor => $cant) {
            if ($cant > 1) {
                $provider_eliminado = Provider::where('user_id', $user->id)
                                        ->where('name', $proveedor)
                                        ->where('status', 'inactive')
                                        ->first();

                $provider_no_eliminado = Provider::where('user_id', $user->id)
                                        ->where('name', $proveedor)
                                        ->where('status', 'active')
                                        ->first();
                if (is_null($provider_no_eliminado)) {
                    echo 'Error con el proveedor '.$proveedor.' </br>';
                } else {
                    Article::where('provider_id', $provider_eliminado->id)
                            ->update([
                                'provider_id' => $provider_no_eliminado->id,
                            ]);
                }
            }
        }

        // var_dump($proveedores_repetidos);
    }

    function codigosRepetidos($company_name) {
        $user = User::where('company_name', $company_name)
                        ->first();
        $articles = Article::where('user_id', $user->id)->get();
        $codigos = [];
        $repetidos = [];

        foreach ($articles as $article) {
            if (array_key_exists($article->bar_code, $codigos)) {
                $codigos[$article->bar_code]++;
                $repetidos[] = $article;
            } else {
                $codigos[$article->bar_code] = 1;
            }
        }
        foreach ($codigos as $codigo => $cantidad) {
            if ($cantidad > 1) {
                echo 'Hay '.$cantidad.' con '.$codigo.' </br>';
            } else {
            }
        }
        // echo '<br>';
        // foreach ($repetidos as $repetido) {
        //     echo $re
        // }
    }

    function setOnlineConfiguration() {
        $users = User::whereNull('owner_id')->get();
        foreach ($users as $user) {
            if (!is_null($user->configuration)) {
                $user->iva_included = $user->configuration->iva_included;
                $user->save();
                OnlineConfiguration::create([
                    'pausar_tienda_online'            => $user->pausar_tienda_online,                     
                    'online_price_type_id'            => $user->online_price_type_id,                     
                    'online_price_surchage'           => $user->online_price_surchage,                      
                    'instagram'                       => $user->instagram,                     
                    'facebook'                        => $user->facebook,                     
                    'quienes_somos'                   => $user->quienes_somos,                     
                    'default_article_image_url'       => $user->default_article_image_url,                     
                    'mensaje_contacto'                => $user->mensaje_contacto,                     
                    'show_articles_without_images'    => $user->show_articles_without_images,                     
                    'show_articles_without_stock'     => $user->show_articles_without_stock,                     
                    'online_description'              => $user->online_description,                     
                    'has_delivery'                    => $user->has_delivery,                     
                    'order_description'               => $user->order_description,
                    'user_id'                         => $user->id,
                ]);
                echo 'Se puso iva_included '.$user->iva_included.' y se creo online_configuration a '.$user->company_name.'</br>';
            }
        }
        // setear config online
    }

    function setComerciocityExtencion() {
        $users = User::whereNull('owner_id')->get();
        foreach ($users as $user) {
            $user->extencions()->attach(9);
            echo 'Se agrego extencion a '.$user->company_name.'</br>';
        }
    }

    function checkClientsSaldos($company_name) {
        $user = User::where('company_name', $company_name)->first();
        ProcessCheckSaldos::dispatch($user);
        echo 'se despacho';
    }

    function checkProvidersSaldos($company_name) {
        $user = User::where('company_name', $company_name)->first();

        $providers = Provider::where('user_id', $user->id)
                            ->get();

        foreach ($providers as $provider) {
            $saldo_anterior = $provider->saldo;
            $provider = CurrentAcountHelper::checkSaldos('provider', $provider->id);
            
            echo 'Se actualizo saldo de '.$provider->name;
            echo '<br>';
            if ($provider->saldo != $saldo_anterior) {
                echo 'Y CAMBIO';
                echo '<br>';
            }
            echo '<br>';
        }
        echo 'Listo';
    }

    function setClientesOscar() {
        $user = User::where('company_name', 'oscar')->first();
        $clients = Client::whereNull('seller_id')
                        ->orWhere('seller_id', 0)
                        ->update([
                            'seller_id' => 9
                        ]);
        echo 'Listo';
    }

    function recaulculateCurrentAcounts($company_name, $client_id = null) {
        ProcessRecalculateCurrentAcounts::dispatch($company_name);
        echo 'Se mando a fila ProcessRecalculateCurrentAcounts';
    }


    // SETEAR TAMBIEN LA IMAGEN DEL USUARIO
    function setProperties($company_name, $for_articles = 0) {
        $for_articles = (boolean) $for_articles;
        $user = User::where('company_name', $company_name)->first();
        $_models = [];
        if ($for_articles) {
            $_models = [
                [
                    'model_name' => 'article',
                ],
            ];
        } else {
            $_models = [
                // [
                //     'model_name' => 'condition',
                // ],  
                // [
                //     'model_name' => 'title',
                // ],  
                // [
                //     'model_name' => 'location',
                // ],
                // [
                //     'model_name' => 'size',
                // ],
                // [
                //     'model_name' => 'deposit',
                // ],
                // [
                //     'model_name' => 'discount',
                // ],
                // [
                //     'model_name' => 'surchage',
                // ],
                // [
                //     'model_name' => 'price_type',
                // ],
                // [
                //     'model_name' => 'recipe',
                // ],
                // [
                //     'model_name' => 'buyer',
                // ],
                // [
                //     'model_name' => 'address',
                //     'plural'     => 'addresses',
                // ],
                // [
                //     'model_name' => 'sale',
                // ],
                [
                    'model_name' => 'provider',
                ],
                [
                    'model_name' => 'client',
                ],
                [
                    'model_name' => 'brand',
                ],
                [
                    'model_name' => 'category',
                    'plural'     => 'categories',
                ],
                [
                    'model_name' => 'sub_category',
                    'plural'     => 'sub_categories',
                ],
            ];
        }
        foreach ($_models as $_model) {
            $id = 1;
            $models = [];
            while (count($models) == 10 || $id == 1) {
                echo 'Entro con '.$_model['model_name'].' id: '.$id.' </br>';
                echo '------------------------------------------------------ </br>';
                $models = GeneralHelper::getModelName($_model['model_name'])::orderBy('id', 'ASC')
                                        ->where('id', '>=', $id)
                                        // ->where('created_at', '>=', Carbon::today()->subWeek())
                                        ->take(10);
                if (!isset($_model['not_from_user_id'])) {
                    $models = $models->where('user_id', $user->id);
                } 
                $models = $models->get();

                foreach ($models as $model) {
                    $model->timestamps = false;
                    $model->num = null;
                    $model->save();
                }
                foreach ($models as $model) {
                    $model->timestamps = false;
                    $model->num = $this->num($this->getPlural($_model), $user->id);
                    $model->save();
                }

                if ($for_articles) {
                    foreach ($models as $model) {
                        if ($model->status == 'inactive') {
                            echo 'Se elimino '.$model->name.' </br>';
                            $model->delete();
                        } else {
                            ArticleHelper::setFinalPrice($model, $user->id);
                            echo('Se seteo precio final de '.$model->name.'. Quedo en '.$model->final_price.' </br>');
                            if (count($model->providers) >= 1) {
                                $model->provider_id = $model->providers[count($model->providers)-1]->id;
                                $model->save(); 
                                // echo $model->name.', proveedor: '.$model->provider->name. ' </br>';
                            }
                            $images = Image::where('article_id', $model->id)->get();
                            foreach($images as $image) {

                                // if (str_contains($image->hosting_url, '/public/public')) {
                                //     $url = $image->hosting_url;
                                //     $new_url = substr($image->hosting_url, 0, 33).'/public'.substr($image->hosting_url, 47);
                                //     $image->hosting_url = $new_url;
                                //     $image->save();
                                //     echo 'entro con '.$model->name.' - '.$url.' </br>';
                                //     echo 'Ahora es '.$new_url.' </br>';
                                //     echo '---------------------- </br>';
                                // }

                                $image->imageable_id = $model->id;
                                $image->imageable_type = 'article';
                                $image->hosting_url = substr($image->hosting_url, 0, 33).'/public'.substr($image->hosting_url, 33);
                                $image->save();
                                echo 'Se actualizo imagen de '.$model->name.' </br>';
                                echo 'Nueva url: '.$image->hosting_url.' </br>';
                                echo '-------------------------------------------- </br>';
                                // if (str_contains($image->hosting_url, 'public/public')) {
                                    
                                // }
                            }
                        }
                    }
                }
                if (count($models) >= 1) {
                    $id = $models[count($models)-1]->id;
                    echo 'ultimo id de '.$_model['model_name'].': '.$id.' </br>';
                } else {
                    $id = 0;
                }
            }
            echo '----------------------- Termino con '.$_model['model_name'].' ------------------------ </br>';
        }
        echo '----------------------- TERMINO ------------------------ </br>';
        // if (!$for_articles) {
            // $user->extencions()->attach([1,2,5,6]);
        // }
        // $articles = Article::where('status', 'active')
        //                     ->where('user_id', $user->id)
        //                     ->get();
        // foreach ($articles as $article) {
        //     $images = Image::where('article_id', $article->id)->get();
        //     foreach($images as $image) {
        //         $image->imageable_id = $article->id;
        //         $image->imageable_type = 'article';
        //         $url = $image->hosting_url;
        //         $url = substr($url, 0, 33).'/public'.substr($url, 33);
        //         $image->hosting_url = $url;
        //         echo 'Se actualzo imagen de '.$article->name.' </br>';
        //         echo 'Nueva url: '.$url.' </br>';
        //         echo '</br> -------------------------------------------- </br>';
        //     }
        // }
    }

    function setClientSeller() {
        // $user = User::where('company_name', $company_name)->first();
        Client::where('user_id', 2)
                ->whereNull('seller_id')
                ->update([
                    'seller_id' => 9,
                ]);
    }

    function clientesRepetidos($company_name) {
        $user = User::where('company_name', $company_name)->first();
        // $clients = Client::where('user_id', $user->id)
        //                     ->where('status', 'active')
        //                     ->where('updated_at', '>=', Carbon::now()->subMinutes(3))
        //                     // ->where('deleted_at', '>=', Carbon::now()->subMinutes(2))
        //                     // ->withTrashed()
        //                     ->orderBy('created_at', 'ASC')
        //                     ->get();

        $clients = Client::where('user_id', $user->id)
                            ->where('status', 'active')
                            ->orderBy('created_at', 'ASC')
                            ->get();
        $repetidos_global = [];
        foreach ($clients as $client) {
            // echo $client->name.' </br>';
            // $client->restore();
            if (!in_array($client->id, $repetidos_global)) {
                $repetidos = Client::where('user_id', $user->id)
                                    ->where('status', 'active')
                                    ->where('name', $client->name)
                                    ->orderBy('created_at', 'ASC')
                                    ->where('id', '!=', $client->id)
                                    ->get();
                if (count($repetidos) >= 1) {
                    echo 'Hay '.count($repetidos).' clientes con el nombre '.$client->name.' repetidos </br>';
                    foreach ($repetidos as $repetido) {
                        echo 'Agregando '.$repetido->name.' id '.$repetido->id. ' </br>';
                        $repetidos_global[] = $repetido->id;
                        $repetido->delete();
                    }
                } 
            } else {
                echo $repetido->name. ' ya estaba eliminado </br>';
            }
        }
        // dd($repetidos_global);
    }

    function checkImages($company_name) {
        $user = User::where('company_name', $company_name)->first();
        $id = 1;
        $models = [];
        while (count($models) == 10 || $id == 1) {
            $models = Article::orderBy('id', 'ASC')
                                ->where('id', '>=', $id)
                                ->take(10)
                                ->where('user_id', $user->id)
                                ->get();

            foreach ($models as $model) {
                $images = Image::where('article_id', $model->id)->get();
                foreach($images as $image) {
                    if (str_contains($image->hosting_url, 'https://api-beta.comerciocity.com/storage')) {
                        $new = substr($image->hosting_url, 0, 34).'public/'.substr($image->hosting_url, 34);
                        $image->imageable_id = $model->id;
                        $image->imageable_type = 'article';
                        $image->hosting_url = $new;
                        $image->save();
                        echo 'Entro con: '.$model->name.': '.$image->hosting_url.' </br>';
                        echo 'Creado: '.$image->created_at.' </br>';
                        echo 'Quedo: '.$image->hosting_url.' </br>';
                        echo '-------------------------------------------- </br>';
                        // $model->save();
                    }
                }
            }
            if (count($models) >= 1) {
                $id = $models[count($models)-1]->id;
            } else {
                $id = 0;
            }
        }
        echo '----------------------- Termino ------------------------ </br>';
    }

    // function clearOrderProductionCurrentAcount($company_name) {
    //     $user = User::where('company_name', $company_name)->first();
    //     $budgets = Budget::where('user_id', $user->id)
    //                     ->get();
    //     foreach ($budgets as $budget) {
    //         $current_acount = CurrentAcount::where('budget_id', $budget->id)->first();
    //         if (!is_null($current_acount)) {
    //             $order_production_current_acount = CurrentAcount::where('client_id', $budget->client_id)
    //                                                                 ->where('debe', $current_acount->debe)
    //                                                                 ->whereNull('budget_id')
    //                                                                 ->first();
    //             if (!is_null($order_production_current_acount)) {
    //                 echo 'Hay un movimiento para el presupuesto N° '.$budget->num.' </br>';
    //                 if (!is_null($budget->client)) {
    //                     echo 'Del cliente '.$budget->client->name.' </br>';
    //                     $saldo_actual = $budget->client->saldo;
    //                 }
    //                 echo 'Y tambien hay uno para la orden de produccion: '.$order_production_current_acount->detalle.' </br>';
    //                 $order_production_current_acount->delete();
    //                 CurrentAcountHelper::checkSaldos('client', $budget->client_id);
    //                 echo 'Se elimino current_acount y se actualizo el saldo, era de '.$saldo_actual.' y ahora es de '.Client::find($budget->client_id)->saldo.' </br>';
    //             }
    //         }
    //     }
    // }

    function clearOrderProductionCurrentAcount($company_name) {
        $user = User::where('company_name', $company_name)->first();
        $order_productions = OrderProduction::where('user_id', $user->id)
                                ->get();
        foreach ($order_productions as $order_production) {
            $current_acount = CurrentAcount::where('order_production_id', $order_production->id)->first();
            if (!is_null($current_acount)) {
                echo 'Hay un movimiento para la orden de produccion: '.$current_acount->detalle.' </br>';
                $current_acount->delete();
                CurrentAcountHelper::checkSaldos('client', $order_production->client_id);
            }
        }
    }

    function deleteClients() {
        Client::where('status', 'inactive')
                ->update([
                    'deleted_at'    => Carbon::now(),
                ]);
    }

    function checkBudgetStatus($company_name) {
        $user = User::where('company_name', $company_name)->first();
        $budgets = Budget::where('user_id', $user->id)
                            ->where('budget_status_id', 1)
                            ->get();
        foreach ($budgets as $budget) {
            $current_acount = CurrentAcount::where('budget_id', $budget->id)
                                            ->first();
            if (!is_null($current_acount)) {
                echo 'Habia una cuenta del cliente '.$current_acount->client->name.' </br>';
                $current_acount->delete();
                CurrentAcountHelper::checkSaldos('client', $current_acount->client_id);
            }
        }
    }

    function checkImageUrl($url) {
        if (str_contains($url, 'https://api-beta.comerciocity.com/public/public')) {
            $url = substr($url, 0, 41).substr($url, 48);
            echo 'Nueva url: '.$url.' </br>';
        }
        return $url;
    }

    function updateImagesFromCloudinary() {
        $user = User::where('company_name', $company_name)->first();
        
        $articles = Article::where('status', 'active')
                            ->where('user_id', $user->id)
                            ->get();
        foreach ($articles as $article) {
            $images = Image::where('article_id', $article->id)->get();
            foreach($images as $image) {
                $image->imageable_id = $article->id;
                $image->imageable_type = 'article';
                if ($from_cloudinary) {
                    $image->hosting_url = 'https://api-empresa.comerciocity.com/public/storage/'.substr($image->hosting_url, 52);
                    $image->save();
                    echo 'Url: '.$image->hosting_url.' </br>';
                    // $url = $this->saveHostingImage($image->url);
                } else {
                    $url = $image->hosting_url;
                    $url = substr($url, 0, 33).'/public'.substr($url, 33);
                    $image->hosting_url = $url;
                    // 52  
                }
                // $url = $image->hosting_url;
                // $url = substr($url, 0, 33).'/public'.substr($url, 33);
                // $image->hosting_url = $url;
                // https://api-empresa.comerciocity./publiccom/storage/
                // $image->save();
                // echo 'Se actualzo imagen de '.$article->name.' </br>';
                // echo 'Nueva url: '.$url.' </br>';
                // echo '</br> -------------------------------------------- </br>';
            }
        }
    }

    function saveHostingImage($cloudinary_url) {
        $array = explode('/', $cloudinary_url);
        $img_prefix = $array[0].'/'.$array[1];
        $name = $array[2];
        $format = explode('.', $name);
        $name = $format[0].'.jpeg';
        $url_cloudinary = 'https://res.cloudinary.com/lucas-cn/image/upload/c_crop,g_custom,q_auto,f_auto/'.$img_prefix.'/'.$name; 
        $file_headers = get_headers($url_cloudinary);
        if (!$file_headers || $file_headers[0] == 'HTTP/1.1 404 Not Found') {
            return null;
        }
        Storage::disk('public')->put($name, file_get_contents($url_cloudinary));
        return env('APP_URL').'/storage/'.$name;
    }

    function getPlural($model) {
        if (isset($model['plural'])) {
            return $model['plural'];
        }
        return $model['model_name'].'s';
    } 

    function restartArticles($user_id) {
        require(__DIR__.'/articles.php');
        // dd($articles);
        foreach ($articles as $article) {
            if ($article['user_id'] == $user_id) {
                $_article = Article::find($article['id']);
                $_article->update([
                    'num'                               => $article['num'],
                    'bar_code'                          => $article['bar_code'],
                    'provider_code'                     => $article['provider_code'],
                    'name'                              => $article['name'],
                    'slug'                              => $article['slug'],
                    'cost'                              => $article['cost'],
                    'price'                             => $article['price'],
                    'final_price'                       => $article['final_price'],
                    'percentage_gain'                   => $article['percentage_gain'],
                    'previus_price'                     => $article['previus_price'],
                    'stock'                             => $article['stock'],
                    'stock_min'                         => $article['stock_min'],
                    'online'                            => $article['online'],
                    'with_dolar'                        => $article['with_dolar'],
                    'user_id'                           => $article['user_id'],
                    'brand_id'                          => $article['brand_id'],
                    'iva_id'                            => $article['iva_id'],
                    'status'                            => $article['status'],
                    'condition_id'                      => $article['condition_id'],
                    'sub_category_id'                   => $article['sub_category_id'],
                    'featured'                          => $article['featured'],
                    'cost_in_dollars'                   => $article['cost_in_dollars'],
                    'provider_cost_in_dollars'          => $article['provider_cost_in_dollars'],
                    'apply_provider_percentage_gain'    => $article['apply_provider_percentage_gain'],
                    'provider_price_list_id'            => $article['provider_price_list_id'],
                    'provider_id'                       => $article['provider_id'],
                    'category_id'                       => $article['category_id'],
                ]);
                ArticleHelper::setFinalPrice($_article, $user_id);
                echo ('Se actualizo '.$_article->name);
                echo('</br> ----------------------------------------------- </br>');
            }
        }
    }
}
