<?php

namespace Database\Seeders;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Models\Article;
use App\Models\ArticleDiscount;
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
        $this->lucas();
    }

    function lucas() {
        $user = User::where('company_name', 'Jugueteria Rosario')
                    ->first();
        $bsas = Provider::where('user_id', $user->id)
                            ->where('name', 'Buenos Aires')
                            ->first();
        $rosario = Provider::where('user_id', $user->id)
                            ->where('name', 'Rosario')
                            ->first();
        $articles = [
            [
                'bar_code'          => '123',
                'provider_code'     => 'p-123',
                'name'              => 'Martillo',
                'stock'             => 10,
                'cost'              => 1000,
                'price'             => 100,
                // 'price'             => 2000,
                'sub_category_name' => 'Martillos',
                'provider_id'       => $bsas->id,
                'featured'          => 1,
                'images'            => [
                    [
                        'url'       => 'martillo.jpg',
                    ],
                ],
            ],
            [
                'featured'          => 2,
                'bar_code'          => '1234',
                'provider_code'     => 'p-1234',
                'name'              => 'Martillo acero',
                'stock'             => 10,
                'cost'              => 2000,
                'price'             => 3000,
                'sub_category_name' => 'Martillos',
                'provider_id'       => $bsas->id,
                'images'            => [
                    [
                        'url'       => 'martillo-acero.jpg',
                    ],
                ],
            ],
            [
                'bar_code'          => '',
                'provider_code'     => '',
                'name'              => 'Pinza',
                'stock'             => 10,
                'cost'              => 1000,
                'price'             => 1500,
                'sub_category_name' => 'Pinzas',
                'provider_id'       => $bsas->id,
                'featured'          => 3,
                'images'            => [
                    [
                        'url'       => 'pinza.jpeg',
                    ],
                ],
            ],
            [
                'bar_code'          => '',
                'provider_code'     => '',
                'name'              => 'Alicate',
                'stock'             => 10,
                'cost'              => 300,
                'price'             => 800,
                'sub_category_name' => 'Pinzas',
                'provider_id'       => $bsas->id,
                'images'            => [
                    [
                        'url'       => 'alicate.jpg',
                    ],
                ],
                'featured'          => 4,
            ],
            [
                'bar_code'          => '',
                'provider_code'     => '',
                'name'              => 'Cuchilla',
                'stock'             => 10,
                'cost'              => 500,
                'price'             => 1000,
                'sub_category_name' => 'Cuchillos',
                'provider_id'       => $bsas->id,
                'featured'          => 5,
                'images'            => [
                    [
                        'url'       => 'cuchilla.webp',
                    ],
                ],
            ],
            [
                'bar_code'          => '',
                'provider_code'     => '',
                'name'              => 'Cuchillo tramontina',
                'stock'             => 10,
                'cost'              => 500,
                'price'             => 1000,
                'sub_category_name' => 'Cuchillos',
                'provider_id'       => $bsas->id,
                'images'            => [
                    [
                        'url'       => 'cuchillo.png',
                    ],
                ],
            ],
            [
                'bar_code'          => '',
                'provider_code'     => '',
                'name'              => 'Cuchara',
                'stock'             => 10,
                'cost'              => 100,
                'price'             => 200,
                'sub_category_name' => 'Cucharas',
                'provider_id'       => $bsas->id,
                'images'            => [
                    [
                        'url'       => 'cuchara.jpg',
                    ],
                ],
            ],
            [
                'bar_code'          => '',
                'provider_code'     => '',
                'name'              => 'Cuchara plastica',
                'stock'             => 10,
                'cost'              => 50,
                'price'             => 100,
                'sub_category_name' => 'Cucharas',
                'provider_id'       => $bsas->id,
                'images'            => [
                    [
                        'url'       => 'cuchara-plastico.jpg',
                    ],
                ],
            ],
            [
                'bar_code'          => '',
                'provider_code'     => '',
                'name'              => 'Mesa de madera',
                'stock'             => 10,
                'cost'              => 4000,
                'price'             => 6000,
                'sub_category_name' => 'Comedor',
                'provider_id'       => $bsas->id,
                'images'            => [
                    [
                        'url'       => 'mesa-madera.jpg',
                    ],
                ],
            ],
            [
                'bar_code'          => '',
                'provider_code'     => '',
                'name'              => 'Mesa barnizada larga',
                'stock'             => 10,
                'cost'              => 7000,
                'price'             => 9000,
                'sub_category_name' => 'Comedor',
                'provider_id'       => $bsas->id,
                'images'            => [
                    [
                        'url'       => 'mesa-larga.jpg',
                    ],
                ],
            ],
            [
                'bar_code'          => '',
                'provider_code'     => '',
                'name'              => 'Cama una plaza',
                'stock'             => 10,
                'cost'              => 7000,
                'price'             => 9000,
                'sub_category_name' => 'Comedor',
                'provider_id'       => $bsas->id,
                'images'            => [
                    [
                        'url'       => 'cama-1.jpg',
                    ],
                ],
            ],
            [
                'bar_code'          => '',
                'provider_code'     => '',
                'name'              => 'Cama dos plazas',
                'stock'             => 10,
                'cost'              => 9000,
                'price'             => 12000,
                'sub_category_name' => 'Comedor',
                'provider_id'       => $bsas->id,
                'images'            => [
                    [
                        'url'       => 'cama-2.jpg',
                    ],
                ],
                'addresses'     => [
                    [
                        'id'        => 1,
                        'amount'    => 10,
                    ],
                    [
                        'id'        => 2,
                        'amount'    => 10,
                    ],
                ],
            ],
            // [
            //     'bar_code'          => '',
            //     'provider_code'     => '',
            //     'name'              => 'Zapatilla adidas',
            //     'stock'             => null,
            //     'cost'              => 10000,
            //     'percentage_gain'   => 50,
            //     'iva_id'            => 6,
            //     'images'            => [
            //         [
            //             'url'       => 'webp.webp',
            //         ],
            //         [
            //             'url'       => 'rasti.jpg',
            //         ],
            //     ],
            // ],
            
        ];
        $num = 1;
        $days = count($articles);
        // for ($i=0; $i < 4; $i++) { 
            foreach ($articles as $article) {
                $art = Article::create([
                    'num'               => $num,
                    'bar_code'          => $article['bar_code'],
                    'provider_code'     => $article['provider_code'],
                    'name'              => $article['name'],
                    'slug'              => ArticleHelper::slug($article['name'], $user->id),
                    'cost'              => $article['cost'],
                    'status'            => isset($article['status']) ? $article['status'] : 'active',
                    'featured'          => isset($article['featured']) ? $article['featured'] : null,
                    'stock'             => $article['stock'] ,
                    'provider_id'       => isset($article['provider_id']) ? $article['provider_id'] : null,
                    'percentage_gain'   => isset($article['percentage_gain']) ? $article['percentage_gain'] : null,
                    'iva_id'            => isset($article['iva_id']) ? $article['iva_id'] : 2,
                    'featured'            => isset($article['featured']) ? $article['featured'] : null,
                    'apply_provider_percentage_gain' => 0,
                    // 'apply_provider_percentage_gain' => 1,
                    'price'             => isset($article['price']) ? $article['price'] : null,
                    'category_id'       => $this->getCategoryId($user, $article),
                    'sub_category_id'   => $this->getSubcategoryId($user, $article),
                    'created_at'        => Carbon::now()->subDays($days),
                    'updated_at'        => Carbon::now()->subDays($days),
                    'user_id'           => $user->id,
                ]);    
                $art->timestamps = false;
                $days--;
                $num++;
                if (isset($article['images'])) {
                    foreach ($article['images'] as $image) { 
                        Image::create([
                            'imageable_type'                            => 'article',
                            'imageable_id'                              => $art->id,
                            env('IMAGE_URL_PROP_NAME', 'image_url')     => env('APP_URL').'/storage/'.$image['url'],
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
                $this->createDescriptions($art); 
                $this->setColors($art, $article); 
                $this->setAddresses($art, $article); 
                ArticleHelper::setFinalPrice($art, $user->id);
            }
        // }
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

    function createDescriptions($article) {
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
