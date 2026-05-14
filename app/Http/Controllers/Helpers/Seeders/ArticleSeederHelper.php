<?php

namespace App\Http\Controllers\Helpers\Seeders;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\article\ArticlePriceTypeMonedaHelper;
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
        $this->price_types = PriceType::all();
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
            'es_insumo'             => isset($article['es_insumo']) ? $article['es_insumo'] : null,
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

        if (isset($article['price_types'])) {
            foreach ($article['price_types'] as $price_type) {
                $created_article->price_types()->attach($price_type['id'], [
                    'percentage'    => $price_type['percentage'],
                ]);
            }
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
            // && isset($article['variants'])
        ) {
            return;
        }

        $ct = new StockMovementController(false);
        
        $data['model_id'] = $created_article->id;
        $data['provider_id'] = $created_article->provider_id;

        if (count($this->addresses) >= 1) {

            $segundos = 0;

            foreach ($this->addresses as $address) {
            
                $data['to_address_id'] = $address->id;
                $data['amount'] = 100;
                $data['concepto_stock_movement_name'] = 'Creacion de deposito';

                $ct->crear($data, false, null, null, $segundos);
                $segundos += 5;
            }

            foreach ($this->addresses as $address) {

                $created_article->addresses()->updateExistingPivot($address->id, [
                    'stock_min'   => 50,
                    'stock_max'   => 120,
                ]);
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

    /**
     * Resuelve subcategoria por nombre; si el payload incluye category_name, acota por categoria padre.
     * Evita ambiguedad cuando el mismo nombre de subcategoria existe bajo distintas categorias.
     *
     * @param array<string,mixed> $article Payload de articulo del seeder (category_name, sub_category_name).
     * @return int|null
     */
    function getSubcategoryId($article) {
        if (!isset($article['sub_category_name'])) {
            return null;
        }

        /** Query base: usuario y nombre de subcategoria. */
        $query = SubCategory::where('user_id', config('app.USER_ID'))
            ->where('name', $article['sub_category_name']);

        /**
         * Si se indico categoria padre, filtrar por su id para matchear el par categoria/sub correcto.
         */
        if (isset($article['category_name'])) {
            $category = Category::where('user_id', config('app.USER_ID'))
                ->where('name', $article['category_name'])
                ->first();
            if (!is_null($category)) {
                $query->where('category_id', $category->id);
            }
        }

        $sub_category = $query->first();

        if ($sub_category) {
            return $sub_category->id;
        }

        return null;
    }

    function add_price_types($article) {

        if (count($this->price_types) >= 1) {

            $article['price_types'] = [];
            foreach ($this->price_types as $price_type) {
                $article['price_types'][] = [
                    'id'            => $price_type->id,
                    'percentage'    => $price_type->percentage,
                ];
            }
        }

        return $article;
    }

    function crear_price_type_monedas($art) {

        $price_type_monedas = [];

        for ($moneda_id=1; $moneda_id <= 2 ; $moneda_id++) { 

            foreach ($this->price_types as $price_type) {
                $price_type_monedas[] = [
                    'price_type_id' => $price_type->id,
                    'moneda_id' => $moneda_id,
                    'setear_precio_final'   => 0,
                    'final_price'   => null,
                    'percentage'    => $price_type->id * 20,
                ];    
            }
        }
        
        ArticlePriceTypeMonedaHelper::attach_price_type_monedas($art, $price_type_monedas, $this->user);

    }

}