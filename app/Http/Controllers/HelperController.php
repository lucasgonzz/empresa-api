<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\CurrentAcountHelper;
use App\Http\Controllers\Helpers\RecalculateCurrentAcountsHelper;
use App\Models\Article;
use App\Models\Budget;
use App\Models\Client;
use App\Models\CurrentAcount;
use App\Models\Image;
use App\Models\OnlineConfiguration;
use App\Models\OrderProduction;
use App\Models\Provider;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class HelperController extends Controller
{

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
        $clients = Client::where('user_id', $user->id)
                            ->get();
        foreach ($clients as $client) {
            CurrentAcountHelper::checkSaldos('client', $client->id);
        }
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

    function recaulculateCurrentAcounts($company_name) {
        $user = User::where('company_name', $company_name)->first();
        $providers = Provider::where('user_id', $user->id)
                            ->get();
        foreach ($providers as $provider) {
            echo 'Proveedor '.$provider->name.' </br>';
            CurrentAcountHelper::checkSaldos('provider', $provider->id);
            CurrentAcountHelper::checkPagos('provider', $provider->id);
            foreach ($provider->current_acounts as $current_acount) {
                echo 'CC del '.date_format($current_acount->created_at, 'd/m/Y').' </br>';
                if (!is_null($current_acount->debe)) {
                    if (!is_null($current_acount->provider_order_id)) {
                        $current_acount->detalle = 'Pedido N°'.$current_acount->provider_order->num;
                    } else {
                        $current_acount->detalle = 'Nota debito';
                    }
                } else if (!is_null($current_acount->haber)) {
                    if ($current_acount->status == 'nota_credito') {
                        $current_acount->detalle = 'Nota Credito N°'.$current_acount->num_receipt;
                    } else {
                        $current_acount->detalle = 'Pago N°'.$current_acount->num_receipt;
                    }
                }
                $current_acount->save();
            }
        }
        return;
        $clients = Client::where('user_id', $user->id)
                            ->get();
        foreach ($clients as $client) {
            echo 'Cliente '.$client->name.' </br>';
            CurrentAcountHelper::checkSaldos('client', $client->id);
            CurrentAcountHelper::checkPagos('client', $client->id);
            foreach ($client->current_acounts as $current_acount) {
                echo 'CC del '.date_format($current_acount->created_at, 'd/m/Y').' </br>';
                if (!is_null($current_acount->debe)) {
                    if (!is_null($current_acount->sale_id)) {
                        $current_acount->detalle = 'Venta N°'.$current_acount->sale->num;
                    } else {
                        $current_acount->detalle = 'Nota debito';
                    }
                } else if (!is_null($current_acount->haber)) {
                    if ($current_acount->status == 'nota_credito') {
                        $current_acount->detalle = 'Nota Credito N°'.$current_acount->num_receipt;
                    } else {
                        $current_acount->detalle = 'Pago N°'.$current_acount->num_receipt;
                    }
                }
                $current_acount->save();
            }
        }
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
                [
                    'model_name' => 'condition',
                ],  
                [
                    'model_name' => 'title',
                ],  
                [
                    'model_name' => 'location',
                ],
                [
                    'model_name' => 'size',
                ],
                [
                    'model_name' => 'deposit',
                ],
                [
                    'model_name' => 'discount',
                ],
                [
                    'model_name' => 'surchage',
                ],
                [
                    'model_name' => 'price_type',
                ],
                [
                    'model_name' => 'recipe',
                ],
                [
                    'model_name' => 'buyer',
                ],
                [
                    'model_name' => 'address',
                    'plural'     => 'addresses',
                ],
                [
                    'model_name' => 'sale',
                ],
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
            $id = 63555;
            $models = [];
            while (count($models) == 10 || $id == 63555) {
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
        if (!$for_articles) {
            $user->extencions()->attach([1,2,5,6]);
        }
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
