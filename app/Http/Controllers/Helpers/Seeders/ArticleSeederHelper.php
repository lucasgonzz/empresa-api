<?php

namespace App\Http\Controllers\Helpers\Seeders;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Stock\StockMovementController;
use App\Models\Address;
use App\Models\Article;
use App\Models\Category;
use App\Models\Description;
use App\Models\Image;
use App\Models\PriceType;
use App\Models\SubCategory;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;


class ArticleSeederHelper {

    public $addresses;

    function __construct() {
        $this->addresses = Address::all();
    }
	   
    function set_provider($created_article, $article) {

        if (isset($article['provider_id'])) {
            $created_article->providers()->attach($article['provider_id'], [
                                        'cost'  => $article['cost'],
                                        'amount' => $article['stock'],
                                    ]);
        }
    }

    function set_images($created_article, $article, $rubro, $formato = 'jpg') {
        $category_name = strtolower($created_article->category->name);
        Image::create([
            'imageable_type'                            => 'article',
            'imageable_id'                              => $created_article->id,
            env('IMAGE_URL_PROP_NAME', 'image_url')     => env('APP_IMAGES_URL').'/storage/'.$rubro.'/'.str_replace(' ', '_', $category_name).'.'.$formato,
        ]);
    }

    function set_stock_movement($created_article, $article) {

        $ct = new StockMovementController();
        
        $data['model_id'] = $created_article->id;
        $data['provider_id'] = $created_article->provider_id;

        if (isset($article['addresses'])) {

            $segundos = 0;

            foreach ($article['addresses'] as $address) {

                Log::info('address del seeder:');
                Log::info($address);

                $address_id = null;

                if (isset($address['id'])) {
                    $address_id = $address['id'];
                } else {

                    $address_id = Address::where('street', $address['name'])->first()->id;
                }
                

                $data['to_address_id'] = $address_id;
                $data['amount'] = $address['amount'];
                $data['concepto_stock_movement_name'] = 'Creacion de deposito';

                $ct->crear($data, false, null, null, $segundos);
                $segundos += 5;
            }

        } else if (count($this->addresses) >= 1) {

            $segundos = 0;

            foreach ($this->addresses as $address) {
            
                $data['to_address_id'] = $address->id;
                $data['amount'] = rand(10, 100);
                $data['concepto_stock_movement_name'] = 'Creacion de deposito';

                $ct->crear($data, false, null, null, $segundos);
                $segundos += 5;
            }
        } else {
            $data['amount'] = rand(10, 100);
            $ct->crear($data);
        }
    }

    function crear_articles($articles) {
        foreach ($articles as $article) {
            $this->crear_article($article);
        }
    }

    function crear_article($article, $days = null) {

        $created_article = Article::create([
            // 'num'                   => $num,
            // 'bar_code'              => $article['name'].rand(99999, 9999999),
            'bar_code'              => isset($article['bar_code']) ? $article['bar_code'] : null,
            'provider_code'         => isset($article['provider_code']) ? $article['provider_code'] : null,
            'name'                  => isset($article['name']) ? $article['name'] : null,
            'slug'                  => ArticleHelper::slug($article['name'], config('app.USER_ID')),
            'cost'                  => isset($article['cost']) ? $article['cost'] : null,
            // 'cost'                  => 100 * $num,
            'price'                 => isset($article['price']) ? $article['price'] : null,
            'cost_in_dollars'                 => isset($article['cost_in_dollars']) ? $article['cost_in_dollars'] : null,
            'costo_mano_de_obra'    => isset($article['costo_mano_de_obra']) ? $article['costo_mano_de_obra'] : null,
            'status'                => isset($article['status']) ? $article['status'] : 'active',
            'featured'              => isset($article['featured']) ? $article['featured'] : null,
            'provider_id'           => isset($article['provider_id']) ? $article['provider_id'] : null,
            'percentage_gain'       => isset($article['percentage_gain']) ? $article['percentage_gain'] : 100,
            'iva_id'                => isset($article['iva_id']) ? $article['iva_id'] : 2,
            'featured'              => isset($article['featured']) ? $article['featured'] : null,
            
            'presentacion'          => isset($article['presentacion']) ? $article['presentacion'] : null,
            
            'apply_provider_percentage_gain'    => isset($article['apply_provider_percentage_gain']) ? $article['apply_provider_percentage_gain'] : 0,
            'default_in_vender'     => isset($article['default_in_vender']) && config('app.FOR_USER') == 'hipermax' ? $article['default_in_vender'] : null,
            'category_id'           => $this->getCategoryId($article),
            'sub_category_id'       => $this->getSubcategoryId($article),
            'created_at'            => !is_null($days) ? Carbon::now()->subDays($days) : Carbon::now(),
            'updated_at'            => !is_null($days) ? Carbon::now()->subDays($days) : Carbon::now(),
            'user_id'               => config('app.USER_ID'),
        ]);    
        $created_article->timestamps = false;
        // $id+;
        if (isset($article['images'])) {
            foreach ($article['images'] as $image) { 
                // $image['url']   = 'https://api-prueba.comerciocity.com/public/storage/171837091695431.webp';
                Image::create([
                    'imageable_type'                            => 'article',
                    'imageable_id'                              => $created_article->id,
                    env('IMAGE_URL_PROP_NAME', 'image_url')     => $image['url'],
                    // env('IMAGE_URL_PROP_NAME', 'image_url')     => config('app.APP_URL').'/storage/'.$image['url'],
                    'color_id'                                  => isset($image['color_id']) ? $image['color_id'] : null,
                ]);
            }    
        }
        if (isset($article['provider_id'])) {
            $created_article->providers()->attach($article['provider_id'], [
                                        'cost'  => $article['cost'],
                                        'amount' => $article['stock'],
                                    ]);
        }

        if (
            isset($article['addresses'])
            || isset($article['set_stock'])
        ) {
            $this->set_stock_movement($created_article, $article);
        }

        return $created_article;
    }

    function setStockMovement($created_article, $article) {

        if (
            config('app.FOR_USER') == 'feito'
            && isset($article['variants'])
        ) {
            return;
        }

        $ct = new StockMovementController(false);
        
        $data['model_id'] = $created_article->id;
        $data['provider_id'] = $created_article->provider_id;

        if (isset($article['addresses'])) {

            $segundos = 0;

            foreach ($article['addresses'] as $address) {
            
                $data['to_address_id'] = $address['id'];
                $data['amount'] = $address['amount'];
                $data['concepto_stock_movement_name'] = 'Creacion de deposito';

                $ct->crear($data, false, null, null, $segundos);
                $segundos += 5;
            }

            foreach ($article['addresses'] as $address) {

                if (isset($address['min'])) {
                    $created_article->addresses()->updateExistingPivot($address['id'], [
                        'stock_min'   => $address['min'],
                        'stock_max'   => $address['max'],
                    ]);
                }
            }



        } else if (
            isset($article['stock'])
            && !is_null($article['stock'])
        ) {
            $data['amount'] = $article['stock'];
            $ct->crear($data);
        }
    }

    function getCategoryId($article) {
        if (isset($article['category_name'])) {
            $category = Category::where('user_id', config('app.USER_ID'))
                                    ->where('name', $article['category_name'])
                                    ->first();
            if (!is_null($category)) {
                return $category->id;
            }
        }
        if (isset($article['sub_category_name'])) {
            $sub_category = SubCategory::where('user_id', config('app.USER_ID'))
                                        ->where('name', $article['sub_category_name'])
                                        ->first();
            if (!is_null($sub_category)) {
                return $sub_category->category_id;
            }
        }
        return null;
    }

    function getSubcategoryId($article) {
        if (isset($article['sub_category_name'])) {
            $sub_category = SubCategory::where('user_id', config('app.USER_ID'))
                                        ->where('name', $article['sub_category_name'])
                                        ->first();

            if ($sub_category) {

                return $sub_category->id;
            }
        }
        return null;
    }

}