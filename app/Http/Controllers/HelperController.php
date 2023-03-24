<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Models\Article;
use App\Models\Image;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class HelperController extends Controller
{

    function setProperties($company_name) {
        $user = User::where('company_name', $company_name)->first();
        
        $_models = [
            [
                'model_name' => 'article',
            ],
            [
                'model_name' => 'condition',
            ],  
            [
                'model_name' => 'title',
            ],  
            // [
            //     'model_name' => 'provider_price_list',
            //     'not_from_user_id' => true,
            // ],
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
            ],
            [
                'model_name' => 'address',
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

        foreach ($_models as $_model) {
            $models = GeneralHelper::getModelName($_model['model_name'])::orderBy('created_at', 'ASC');
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
                $model->num = $this->num($this->getPlural($_model), $user->id);
                $model->save();
            }
            // echo 'Se actualzaron '.$_model['model_name'];
            // echo '</br> -------------------------------------------- </br>';
        }

        $articles = Article::where('status', 'active')
                            ->where('user_id', $user->id)
                            ->get();
        foreach ($articles as $article) {
            $images = Image::where('article_id', $article->id)->get();
            foreach($images as $image) {
                // if (is_null($image->imageable_id)) 
                $image->imageable_id = $article->id;
                $image->imageable_type = 'article';
                $url = $image->hosting_url;
                $url = substr($url, 0, 33).'/public'.substr($url, 33);
                $image->hosting_url = $url;
                $image->save();
                echo 'Se actualzo imagen de '.$article->name.' </br>';
                echo 'Nueva url: '.$url.' </br>';
                echo '</br> -------------------------------------------- </br>';
            }
        }
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
