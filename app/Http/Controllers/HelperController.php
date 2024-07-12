<?php

namespace App\Http\Controllers;

use App\Exports\SalesExport;
use App\Http\Controllers\AfipWsController;
use App\Http\Controllers\ArticlePerformanceController;
use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\CommonLaravel\Helpers\Numbers;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\CartArticleAmountInsificienteHelper;
use App\Http\Controllers\Helpers\CurrentAcountHelper;
use App\Http\Controllers\Helpers\InventoryLinkageHelper;
use App\Http\Controllers\Helpers\RecalculateCurrentAcountsHelper;
use App\Http\Controllers\Helpers\RecipeHelper;
use App\Http\Controllers\StockMovementController;
use App\Jobs\ProcessCheckInventoryLinkages;
use App\Jobs\ProcessCheckSaldos;
use App\Jobs\ProcessRecalculateCurrentAcounts;
use App\Jobs\ProcessSetStockResultante;
use App\Models\Article;
use App\Models\Budget;
use App\Models\Buyer;
use App\Models\Category;
use App\Models\Client;
use App\Models\CurrentAcount;
use App\Models\CurrentAcountCurrentAcountPaymentMethod;
use App\Models\Image;
use App\Models\InventoryLinkage;
use App\Models\OnlineConfiguration;
use App\Models\OrderProduction;
use App\Models\Provider;
use App\Models\Recipe;
use App\Models\Sale;
use App\Models\SaleModification;
use App\Models\StockMovement;
use App\Models\SubCategory;
use App\Models\User;
use App\Models\UserConfiguration;
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

    function register_user($name, $doc_number, $company_name, $iva_included, $extencions_id, $database = null) {

        $data = [
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

                $afip = new AfipWsController($sale);
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

    function codigosRepetidos() {
        $user = User::where('doc_number', '09876543')
                        ->first();
        $articles = Article::where('user_id', $user->id)->get();
        $codigos = [];
        foreach ($articles as $article) {
            if (array_key_exists($article->bar_code, $codigos)) {
                $codigos[$article->bar_code]++;
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
