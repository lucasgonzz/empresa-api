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
    ];

    public function run()
    {
        $this->lucas();
    }

    function lucas() {
        $user = User::where('company_name', 'lucas')
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
                'name'              => 'Plaqueta de BSAS',
                'stock'             => 10,
                'cost'              => 100,
                'price'             => null,
                'sub_category_name' => 'lavarropa nuevo',
                'provider_id'       => $bsas->id,
                'images'            => [
                    [
                        'url'       => $this->images['cadena'],
                    ],
                    [
                        'url'       => $this->images['mochila'],
                    ],
                ],
                'colors'            => [],
                'sizes'             => [],
            ],
            [
                'bar_code'          => '',
                'provider_code'     => '',
                'name'              => 'Plaqueta de BSAS 2',
                'stock'             => 10,
                'cost'              => 100,
                'price'             => null,
                'sub_category_name' => 'lavarropa nuevo',
                'provider_id'       => $bsas->id,
                'featured'          => 1,
                'images'            => [
                    [
                        'url'       => $this->images['cadena'],
                        'color_id'  => 1,
                    ],
                    [
                        'url'       => $this->images['mochila'],
                        'color_id'  => 2,
                    ],
                ],
                'colors'            => [
                    [
                        'id'        => 1,
                        'amount'    => 1,
                    ],
                    [
                        'id'        => 2,
                        'amount'    => 1,
                    ],
                ],
                'sizes'             => [],
            ],
            [
                'bar_code'          => '',
                'provider_code'     => '',
                'name'              => 'Plaqueta de BSAS 3',
                'stock'             => 10,
                'cost'              => 100,
                'price'             => null,
                'sub_category_name' => 'lavarropa nuevo',
                'provider_id'       => $bsas->id,
                'featured'          => 2,
                'images'            => [
                    [
                        'url'       => $this->images['cadena'],
                        'color_id'  => 1,
                    ],
                    [
                        'url'       => $this->images['mochila'],
                        'color_id'  => 2,
                    ],
                ],
                'colors'            => [
                    [
                        'id'        => 1,
                        'amount'    => 1,
                    ],
                    [
                        'id'        => 2,
                        'amount'    => 0,
                    ],
                ],
                'sizes'             => [],
            ],
            [
                'bar_code'          => '',
                'provider_code'     => '',
                'name'              => 'Plaqueta de BSAS 4',
                'stock'             => 10,
                'cost'              => 100,
                'price'             => null,
                'sub_category_name' => 'lavarropa nuevo',
                'provider_id'       => $bsas->id,
                'images'            => [
                    [
                        'url'       => $this->images['cadena'],
                    ],
                    [
                        'url'       => $this->images['mochila'],
                    ],
                ],
                'colors'            => [],
                'sizes'             => [],
            ],
            [
                'bar_code'          => '',
                'provider_code'     => '',
                'name'              => 'Plaqueta de BSAS 5',
                'stock'             => 10,
                'cost'              => 100,
                'price'             => null,
                'sub_category_name' => 'lavarropa nuevo',
                'provider_id'       => $bsas->id,
                'images'            => [
                    [
                        'url'       => $this->images['cadena'],
                    ],
                    [
                        'url'       => $this->images['mochila'],
                    ],
                ],
                'colors'            => [],
                'sizes'             => [],
            ],
            [
                'bar_code'          => '',
                'provider_code'     => '',
                'name'              => 'Plaqueta de BSAS 6',
                'stock'             => 10,
                'cost'              => 100,
                'price'             => null,
                'sub_category_name' => 'lavarropa nuevo',
                'provider_id'       => $bsas->id,
                'images'            => [
                    [
                        'url'       => $this->images['cadena'],
                    ],
                    [
                        'url'       => $this->images['mochila'],
                    ],
                ],
                'colors'            => [],
                'sizes'             => [],
            ],
            [
                'bar_code'          => '',
                'provider_code'     => '',
                'name'              => 'Plaqueta de BSAS 7',
                'stock'             => 10,
                'cost'              => 100,
                'price'             => null,
                'sub_category_name' => 'lavarropa nuevo',
                'provider_id'       => $bsas->id,
                'images'            => [
                    [
                        'url'       => $this->images['cadena'],
                    ],
                    [
                        'url'       => $this->images['mochila'],
                    ],
                ],
                'colors'            => [],
                'sizes'             => [],
            ],
            [
                'bar_code'          => '',
                'provider_code'     => '',
                'name'              => 'Plaqueta de BSAS 8',
                'stock'             => 10,
                'cost'              => 100,
                'price'             => null,
                'sub_category_name' => 'lavarropa nuevo',
                'provider_id'       => $bsas->id,
                'images'            => [
                    [
                        'url'       => $this->images['cadena'],
                    ],
                    [
                        'url'       => $this->images['mochila'],
                    ],
                ],
                'colors'            => [],
                'sizes'             => [],
            ],
            [
                'bar_code'          => '234',
                'provider_code'     => 'p-234',
                'name'              => 'Plaqueta de Rosario',
                'stock'             => 10,
                'cost'              => 100,
                'price'             => null,
                'sub_category_name' => 'lavarropas usados',
                'provider_id'       => $rosario->id,
                'images'            => [
                    [
                        'url'       => $this->images['cadena'],
                    ],
                    [
                        'url'       => $this->images['mochila'],
                    ],
                ],
                'colors'            => [],
                'sizes'             => [],
            ],
            [
                'bar_code'          => '345',
                'provider_code'     => 'p-345',
                'name'              => 'Aire de BSAS',
                'stock'             => 10,
                'cost'              => 200,
                'price'             => null,
                'sub_category_name' => 'aire nuevo',
                'provider_id'       => $bsas->id,
                'images'            => [
                    [
                        'url'       => $this->images['cadena'],
                    ],
                    [
                        'url'       => $this->images['mochila'],
                    ],
                ],
                'colors'            => [],
                'sizes'             => [],
            ],
            [
                'bar_code'          => '456',
                'provider_code'     => 'p-456',
                'name'              => 'Aire de Rosario',
                'stock'             => 10,
                'cost'              => 200,
                'price'             => null,
                'sub_category_name' => 'aires acondicionados usados',
                'provider_id'       => $rosario->id,
                'images'            => [
                    [
                        'url'       => $this->images['cadena'],
                    ],
                    [
                        'url'       => $this->images['mochila'],
                    ],
                ],
                'colors'            => [],
                'sizes'             => [],
            ],
            [
                'bar_code'          => '',
                'provider_code'     => '',
                'name'              => 'Computadora lenovo',
                'sub_category_name' => 'computacion 1',
                'stock'             => 100,
                'cost'              => 5,
                'price'             => 10,
                'provider_id'       => $rosario->id,
                'images'            => [
                    [
                        'url'       => $this->images['cadena'],
                    ],
                ],
            ],
            [
                'bar_code'          => '',
                'provider_code'     => '',
                'name'              => 'Computadora ACME',
                'sub_category_name' => 'computacion 2',
                'stock'             => 100,
                'cost'              => 5,
                'price'             => 10,
                'provider_id'       => $rosario->id,
                'images'            => [
                    [
                        'url'       => $this->images['cadena'],
                    ],
                ],
            ],
            [
                'bar_code'          => '',
                'provider_code'     => '',
                'name'              => 'Tanques de oxigeno',
                'sub_category_name' => 'Tanques de oxigeno 1',
                'stock'             => 100,
                'cost'              => 5,
                'price'             => 10,
                'provider_id'       => $rosario->id,
                'images'            => [
                    [
                        'url'       => $this->images['cadena'],
                    ],
                ],
            ],
            [
                'bar_code'          => '',
                'provider_code'     => '',
                'name'              => 'Tanques de oxigeno',
                'sub_category_name' => 'Tanques de oxigeno 2',
                'stock'             => 100,
                'cost'              => 5,
                'price'             => 10,
                'provider_id'       => $rosario->id,
                'images'            => [
                    [
                        'url'       => $this->images['cadena'],
                    ],
                ],
            ],
            [
                'bar_code'          => '',
                'provider_code'     => '',
                'name'              => 'cosas para la casa A',
                'sub_category_name' => 'cosas para la casa 1',
                'stock'             => 100,
                'cost'              => 5,
                'price'             => 10,
                'provider_id'       => $rosario->id,
                'images'            => [
                    [
                        'url'       => $this->images['cadena'],
                    ],
                ],
            ],
            [
                'bar_code'          => '',
                'provider_code'     => '',
                'name'              => 'cosas para la casa B',
                'sub_category_name' => 'cosas para la casa 2',
                'stock'             => 100,
                'cost'              => 5,
                'price'             => 10,
                'provider_id'       => $rosario->id,
                'images'            => [
                    [
                        'url'       => $this->images['cadena'],
                    ],
                ],
            ],
            [
                'bar_code'          => '',
                'provider_code'     => '',
                'name'              => 'Repuestos de lavarropas A',
                'sub_category_name' => 'Repuestos de lavarropas 1',
                'stock'             => 100,
                'cost'              => 5,
                'price'             => 10,
                'provider_id'       => $rosario->id,
                'images'            => [
                    [
                        'url'       => $this->images['cadena'],
                    ],
                ],
            ],
            [
                'bar_code'          => '',
                'provider_code'     => '',
                'name'              => 'Repuestos de lavarropas B',
                'sub_category_name' => 'Repuestos de lavarropas 2',
                'stock'             => 100,
                'cost'              => 5,
                'price'             => 10,
                'provider_id'       => $rosario->id,
                'images'            => [
                    [
                        'url'       => $this->images['cadena'],
                    ],
                ],
            ],
            [
                'bar_code'          => '',
                'provider_code'     => '',
                'name'              => 'Repuestos de muchas cosas man',
                'category_name'     => 'repuestos de muchas cosas',
                'stock'             => 100,
                'cost'              => 5,
                'price'             => 10,
                'provider_id'       => $rosario->id,
                'images'            => [
                    [
                        'url'       => $this->images['cadena'],
                    ],
                ],
            ],
            [
                'bar_code'          => '',
                'provider_code'     => '',
                'name'              => 'Tornillo num 6',
                'stock'             => 100,
                'cost'              => 5,
                'price'             => 10,
                'provider_id'       => $rosario->id,
                'images'            => [
                    [
                        'url'       => $this->images['cadena'],
                    ],
                ],
            ],
            [
                'bar_code'          => '',
                'provider_code'     => '',
                'name'              => 'Boton chico blanco',
                'stock'             => 100,
                'cost'              => 4,
                'price'             => 7,
                'provider_id'       => $rosario->id,
                'images'            => [
                    [
                        'url'       => $this->images['cadena'],
                    ],
                ],
            ],
            [
                'bar_code'          => '',
                'provider_code'     => '',
                'name'              => 'Cable 10cm',
                'stock'             => 100,
                'cost'              => 10,
                'price'             => 15,
                'provider_id'       => $rosario->id,
                'images'            => [
                    [
                        'url'       => $this->images['cadena'],
                    ],
                ],
            ],
            [
                'bar_code'          => '',
                'provider_code'     => '',
                'name'              => 'Carcaza negra',
                'stock'             => 100,
                'cost'              => 10,
                'price'             => 30,
                'provider_id'       => $rosario->id,
                'images'            => [
                    [
                        'url'       => $this->images['cadena'],
                    ],
                ],
            ],
            [
                'bar_code'          => '',
                'provider_code'     => '',
                'name'              => 'Inactivoo',
                'status'            => 'inactive',
                'stock'             => 100,
                'cost'              => 10,
                'price'             => 30,
                'provider_id'       => $rosario->id,
                'images'            => [
                    [
                        'url'       => $this->images['cadena'],
                    ],
                ],
            ],
        ];
        $num = 1;
        $days = count($articles);
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
                'provider_id'       => $article['provider_id'],
                'apply_provider_percentage_gain' => 1,
                'price'             => $article['price'],
                'category_id'       => $this->getCategoryId($user, $article),
                'sub_category_id'   => $this->getSubcategoryId($user, $article),
                'created_at'        => Carbon::now()->subDays($days),
                'user_id'           => $user->id,
            ]);    
            $days--;
            $num++;
            if (isset($article['images'])) {
                foreach ($article['images'] as $image) { 
                    Image::create([
                        'imageable_type'                            => 'article',
                        'imageable_id'                              => $art->id,
                        env('IMAGE_URL_PROP_NAME', 'image_url')     => $image['url'],
                        'color_id'                                  => isset($image['color_id']) ? $image['color_id'] : null,
                    ]);
                }    
            }
            $art->providers()->attach($article['provider_id'], [
                                        'cost'  => $article['cost'],
                                        'amount' => $article['stock'],
                                    ]);
            $this->createDescriptions($art); 
            $this->setColors($art, $article); 
            ArticleHelper::setFinalPrice($art, $user->id);
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
            'content'    => 'Este modelo nos entrega una importante capacidad de almacenamiento loco mal esta re zarpada pero mal mal mal. Este modelo nos entrega una importante capacidad de almacenamiento loco mal esta re zarpada pero mal mal mal. Este modelo nos entrega una importante capacidad de almacenamiento loco mal esta re zarpada pero mal mal mal',
            'article_id' => $article->id,
        ]);
        Description::create([
            'title'      => 'Pantalla',
            'content'    => 'Tiene una pantalla muy linda y bueno nada esta todo re bien viste mas que bien',
            'article_id' => $article->id,
        ]);
        Description::create([
            'title'      => 'Bateria',
            'content'    => 'La bateria se la re aguanta mal mal mal La bateria se la re aguanta mal mal mal La bateria se la re aguanta mal mal mal ',
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
