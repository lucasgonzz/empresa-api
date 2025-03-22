<?php

namespace Database\Seeders;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Stock\StockMovementController;
use App\Models\Article;
use App\Models\ArticleDiscount;
use App\Models\ArticleProperty;
use App\Models\ArticlePropertyType;
use App\Models\ArticlePropertyValue;
use App\Models\Category;
use App\Models\Description;
use App\Models\Image;
use App\Models\Provider;
use App\Models\SubCategory;
use App\Models\User;
use App\Models\Variant;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ArticleSeeder extends Seeder
{


    public $images = [
        'cubo' => 'http://empresa.local:8000/storage/cubo.jpeg',
        'cadena' => 'http://empresa.local:8000/storage/cadena.jpg',
        'mochila' => 'http://empresa.local:8000/storage/mochila.jpg',
        'martillo' => 'http://empresa.local:8000/storage/martillo.jpg',
    ];

    public function run()
    {
        $this->for_user = env('FOR_USER');

        $this->lucas();
    }

    function lucas() {
        $user = User::where('company_name', 'Autopartes Boxes')
                    ->first();
        $bsas = Provider::where('user_id', $user->id)
                            ->where('name', 'Buenos Aires')
                            ->first();
        $rosario = Provider::where('user_id', $user->id)
                            ->where('name', 'Rosario')
                            ->first();

        require('articles/supermercado.php');

        $articles = $this->add_defaults_in_vender($articles);

        $num = 1;
        $days = count($articles);

        foreach ($articles as $article) {

            $art = Article::create([
                'num'                   => $num,
                // 'bar_code'              => $article['name'].rand(99999, 9999999),
                'bar_code'              => '00'.$num,
                'provider_code'         => 'p/'.$num,
                'name'                  => $article['name'],
                'slug'                  => ArticleHelper::slug($article['name'], $user->id),
                'cost'                  => $article['cost'],
                // 'cost'                  => 100 * $num,
                'price'                 => isset($article['price']) ? $article['price'] : null,
                'costo_mano_de_obra'    => isset($article['costo_mano_de_obra']) ? $article['costo_mano_de_obra'] : null,
                'status'                => isset($article['status']) ? $article['status'] : 'active',
                'featured'              => isset($article['featured']) ? $article['featured'] : null,
                'provider_id'           => isset($article['provider_id']) ? $article['provider_id'] : null,
                'percentage_gain'       => 100,
                'iva_id'                => isset($article['iva_id']) ? $article['iva_id'] : null,
                'featured'              => isset($article['featured']) ? $article['featured'] : null,
                // 'apply_provider_percentage_gain'     => 0,
                'apply_provider_percentage_gain'    => 0,
                'default_in_vender'     => isset($article['default_in_vender']) && $this->for_user == 'hipermax' ? $article['default_in_vender'] : null,
                'category_id'           => $this->getCategoryId($user, $article),
                'sub_category_id'       => $this->getSubcategoryId($user, $article),
                'created_at'            => Carbon::now()->subDays($days),
                'updated_at'            => Carbon::now()->subDays($days),
                'user_id'               => $user->id,
            ]);    
            $art->timestamps = false;
            $days--;
            $num++;
            // $id+;
            if (isset($article['images'])) {
                foreach ($article['images'] as $image) { 
                    // $image['url']   = 'https://api-prueba.comerciocity.com/public/storage/171837091695431.webp';
                    Image::create([
                        'imageable_type'                            => 'article',
                        'imageable_id'                              => $art->id,
                        env('IMAGE_URL_PROP_NAME', 'image_url')     => $image['url'],
                        // env('IMAGE_URL_PROP_NAME', 'image_url')     => env('APP_URL').'/storage/'.$image['url'],
                        'color_id'                                  => isset($image['color_id']) ? $image['color_id'] : null,
                    ]);
                }    
            }
            if (isset($article['provider_id'])) {
                $art->providers()->attach($article['provider_id'], [
                                            'cost'  => $article['cost'],
                                            'amount' => $article['stock'],
                                        ]);
            }

            $this->check_variants($art, $article);

            $this->check_precios_en_blanco($art);

            $this->createDescriptions($art, $article); 
            $this->setColors($art, $article); 
            // $this->setAddresses($art, $article); 
            ArticleHelper::setFinalPrice($art, $user->id);
            $this->setStockMovement($art, $article);
            // ArticleHelper::setArticleStockFromAddresses($art);
        }
        // }
    }

    function add_defaults_in_vender($articles) {
        if ($this->for_user == 'hipermax') {
            return array_merge($articles, [
                [
                    'bar_code'          => null,
                    'provider_code'     => null,
                    'iva_id'            => null,
                    'name'              => 'Varios',
                    'stock'             => null,
                    'cost'              => null,
                    'sub_category_name' => null,
                    'provider_id'       => null,
                    'default_in_vender' => 1,
                ],
            ]);
        }
        return $articles;
    }

    function check_variants($created_article, $article) {
        if (env('FOR_USER') == 'feito') {
            if (isset($article['variants'])) {
                foreach ($article['variants']['article_properties'] as $article_property) {
                    $article_property_type = ArticlePropertyType::where('name', $article_property['article_property_type'])->first();

                    $article_property_model = ArticleProperty::create([
                        'article_id'    => $created_article->id,
                        'article_property_type_id'  => $article_property_type->id,
                    ]);

                    foreach ($article_property['article_property_values'] as $article_property_value) {
                        
                        $article_property_value_model = ArticlePropertyValue::where('name', $article_property_value)->first();

                        $article_property_model->article_property_values()->attach($article_property_value_model->id);
                    }
                }
            }
        }
    }

    function check_precios_en_blanco($article) {

        if ($this->for_user == 'ros_mar') {

            $article->percentage_gain_blanco = 200;
            $article->save();
        }
    }

    function setStockMovement($created_article, $article) {

        if (
            env('FOR_USER') == 'feito'
            && isset($article['variants'])
        ) {
            return;
        }

        $ct = new StockMovementController();
        
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
        } else if (
            isset($article['stock'])
            && !is_null($article['stock'])
        ) {
            $data['amount'] = $article['stock'];
            $ct->crear($data);
        }
    }

    function createDiscount($article) {
        ArticleDiscount::create([
            'percentage' => '10',
            'article_id' => $article->id,
        ]);
        ArticleDiscount::create([
            'percentage' => '20',
            'article_id' => $article->id,
        ]);
    }

    function setColors($article, $_article) {
        if (isset($_article['colors'])) {
            foreach ($_article['colors'] as $color) {
                $article->colors()->attach($color['id'], [
                    'amount'    => $color['amount'],
                ]);
            }
        }
    }

    function setAddresses($article, $_article) {
        if (isset($_article['addresses'])) {
            foreach ($_article['addresses'] as $address) {
                $article->addresses()->attach($address['id'], [
                    'amount'    => $address['amount'],
                ]);
            }
        }
    }

    function getCategoryId($user, $article) {
        if (isset($article['category_name'])) {
            $category = Category::where('user_id', $user->id)
                                    ->where('name', $article['category_name'])
                                    ->first();
            if (!is_null($category)) {
                return $category->id;
            }
        }
        if (isset($article['sub_category_name'])) {
            $sub_category = SubCategory::where('user_id', $user->id)
                                        ->where('name', $article['sub_category_name'])
                                        ->first();
            if (!is_null($sub_category)) {
                return $sub_category->category_id;
            }
        }
        return null;
    }

    function getSubcategoryId($user, $article) {
        if (isset($article['sub_category_name'])) {
            $sub_category = SubCategory::where('user_id', $user->id)
                                        ->where('name', $article['sub_category_name'])
                                        ->first();
            return $sub_category->id;
        }
        return null;
    }

    function getColorId($article) {
        if (isset($article['colors']) && count($article['colors']) >= 1) {
            return $article['colors'][0];
        }
        return null;
    }

    function createDescriptions($created_article, $article) {
        if (isset($article['descriptions'])) {
            Description::create([
                'title'      => 'Almacentamiento',
                'content'    => 'Este modelo nos entrega una importante capacidad de almacenamiento.',
                'article_id' => $created_article->id,
            ]);
        }
        return;
        Description::create([
            'title'      => 'Almacentamiento',
            'content'    => 'Este modelo nos entrega una importante capacidad de almacenamiento.',
            'article_id' => $article->id,
        ]);
        Description::create([
            'title'      => 'Pantalla',
            'content'    => 'Tiene una pantalla muy linda',
            'article_id' => $article->id,
        ]);
        Description::create([
            'title'      => 'Materiales',
            'content'    => 'Esta hecho con los mejores materiales de construccion',
            'article_id' => $article->id,
        ]);
    }

    function subcategoryId($user_id, $i) {
        if ($user_id < 3) {
            return rand(1,40);
        } else {
            if ($i <= 10) {
                $sub_category = SubCategory::where('name', 'Iphones')->first();
                return $sub_category->id;
            }
            if ($i > 10 && $i <= 12) {
                $sub_category = SubCategory::where('name', 'Iphon')->first();
                return $sub_category->id;
            }
            if ($i > 12 && $i <= 14) {
                $sub_category = SubCategory::where('name', 'Android')->first();
                return $sub_category->id;
            }
            if ($i > 14 && $i <= 16) {
                $sub_category = SubCategory::where('name', 'Casco')->first();
                return $sub_category->id;
            }
            if ($i > 16 && $i <= 18) {
                $sub_category = SubCategory::where('name', 'Comunes')->first();
                return $sub_category->id;
            }
        }
    }

}
