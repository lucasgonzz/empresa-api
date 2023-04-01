<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Models\Article;
use App\Models\Image;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class HelperController extends Controller
{

    function setProperties($company_name) {
        $user = User::where('company_name', $company_name)->first();
        
        $_models = [
            [
                'model_name' => 'article',
            ],
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
            // ],
            // [
            //     'model_name' => 'address',
            // ],
            // [
            //     'model_name' => 'sale',
            // ],
            // [
            //     'model_name' => 'provider',
            // ],
            // [
            //     'model_name' => 'client',
            // ],
            // [
            //     'model_name' => 'brand',
            // ],
            // [
            //     'model_name' => 'category',
            //     'plural'     => 'categories',
            // ],
            // [
            //     'model_name' => 'sub_category',
            //     'plural'     => 'sub_categories',
            // ],
        ];
        echo "Va </br>";
        foreach ($_models as $_model) {
            $id = 1;
            $models = [];
            while (count($models) == 10 || $id == 1) {
                echo 'entro con '.$_model['model_name'].' id: '.$id.' </br>';
                $models = GeneralHelper::getModelName($_model['model_name'])::orderBy('id', 'ASC')
                                        ->where('id', '>=', $id)
                                        ->take(10);
                if (!isset($_model['not_from_user_id'])) {
                    $models = $models->where('user_id', $user->id);
                } 
                $models = $models->get();

                // foreach ($models as $model) {
                //     $model->timestamps = false;
                //     $model->num = null;
                //     $model->save();
                // }
                // foreach ($models as $model) {
                //     $model->num = $this->num($this->getPlural($_model), $user->id);
                //     $model->save();
                // }

                foreach ($models as $model) {
                    $images = Image::where('article_id', $model->id)->get();
                    foreach($images as $image) {
                        $image->hosting_url = $this->checkImageUrl($image->hosting_url);
                        $image->save();
                        // $image->imageable_id = $model->id;
                        // $image->imageable_type = 'article';
                        // $url = $image->hosting_url;
                        // $url = substr($url, 0, 33).'/public'.substr($url, 33);
                        // $image->hosting_url = $url;
                        // $image->save();
                        // echo 'Se actualzo imagen de '.$model->name.' </br>';
                        // echo 'Nueva url: '.$url.' </br>';
                        // echo '</br> -------------------------------------------- </br>';
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
        return;

        // $articles = Article::where('status', 'active')
        //                     ->where('user_id', $user->id)
        //                     ->get();
        foreach ($articles as $article) {
            $images = Image::where('article_id', $article->id)->get();
            foreach($images as $image) {
                $image->imageable_id = $article->id;
                $image->imageable_type = 'article';
                $url = $image->hosting_url;
                $url = substr($url, 0, 33).'/public'.substr($url, 33);
                $image->hosting_url = $url;
                echo 'Se actualzo imagen de '.$article->name.' </br>';
                echo 'Nueva url: '.$url.' </br>';
                echo '</br> -------------------------------------------- </br>';
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
