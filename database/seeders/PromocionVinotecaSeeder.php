<?php

namespace Database\Seeders;

use App\Http\Controllers\Helpers\PromocionVinotecaHelper;
use App\Models\Image;
use App\Models\PromocionVinoteca;
use Illuminate\Database\Seeder;

class PromocionVinotecaSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        sleep(1);
        $models = [
            [
                'name'  => 'Promo 1',
                'cost' => 6000,
                'final_price' => 10000,
                'stock' => 10,
                'description'   => 'Descripcion',
                'address_id'   => 1,
                'articles'      => [
                    [
                        'id'    => 1,
                        'pivot' => [
                            'amount'    => 1,
                            'unidades_por_promo'    => 3,
                        ],
                    ],
                    [
                        'id'    => 2,
                        'pivot' => [
                            'amount'    => 2,
                            'unidades_por_promo'    => 3,
                        ],
                    ],
                ],
                'image' => env('APP_IMAGES_URL').'storage/vinoteca/promo.webp',
            ],
            [
                'name'  => 'Promo 2',
                'cost' => 6000,
                'final_price' => 10000,
                'stock' => 10,
                'description'   => 'Descripcion',
                'address_id'   => 1,
                'articles'      => [
                    [
                        'id'    => 1,
                        'pivot' => [
                            'amount'    => 1,
                            'unidades_por_promo'    => 3,
                        ],
                    ],
                    [
                        'id'    => 2,
                        'pivot' => [
                            'amount'    => 2,
                            'unidades_por_promo'    => 3,
                        ],
                    ],
                ],
                'image' => env('APP_IMAGES_URL').'storage/vinoteca/promo2.webp',
            ],
            [
                'name'  => 'Promo 3',
                'cost' => 6000,
                'final_price' => 10000,
                'stock' => 10,
                'description'   => 'Descripcion',
                'address_id'   => 1,
                'articles'      => [
                    [
                        'id'    => 1,
                        'pivot' => [
                            'amount'    => 1,
                            'unidades_por_promo'    => 3,
                        ],
                    ],
                    [
                        'id'    => 2,
                        'pivot' => [
                            'amount'    => 2,
                            'unidades_por_promo'    => 3,
                        ],
                    ],
                ],
                'image' => env('APP_IMAGES_URL').'storage/vinoteca/promo3.webp',
            ],
            [
                'name'  => 'Promo 4',
                'cost' => 6000,
                'final_price' => 10000,
                'stock' => 10,
                'description'   => 'Descripcion',
                'address_id'   => 1,
                'articles'      => [
                    [
                        'id'    => 1,
                        'pivot' => [
                            'amount'    => 1,
                            'unidades_por_promo'    => 3,
                        ],
                    ],
                    [
                        'id'    => 2,
                        'pivot' => [
                            'amount'    => 2,
                            'unidades_por_promo'    => 3,
                        ],
                    ],
                ],
                'image' => env('APP_IMAGES_URL').'storage/vinoteca/promo.webp',
            ],
            [
                'name'  => 'Promo 5',
                'cost' => 6000,
                'final_price' => 10000,
                'stock' => 10,
                'description'   => 'Descripcion',
                'address_id'   => 1,
                'articles'      => [
                    [
                        'id'    => 1,
                        'pivot' => [
                            'amount'    => 1,
                            'unidades_por_promo'    => 3,
                        ],
                    ],
                    [
                        'id'    => 2,
                        'pivot' => [
                            'amount'    => 2,
                            'unidades_por_promo'    => 3,
                        ],
                    ],
                ],
                'image' => env('APP_IMAGES_URL').'storage/vinoteca/promo2.webp',
            ],
            [
                'name'  => 'Promo 6',
                'cost' => 6000,
                'final_price' => 10000,
                'stock' => 10,
                'description'   => 'Descripcion',
                'address_id'   => 1,
                'articles'      => [
                    [
                        'id'    => 1,
                        'pivot' => [
                            'amount'    => 1,
                            'unidades_por_promo'    => 3,
                        ],
                    ],
                    [
                        'id'    => 2,
                        'pivot' => [
                            'amount'    => 2,
                            'unidades_por_promo'    => 3,
                        ],
                    ],
                ],
                'image' => env('APP_IMAGES_URL').'storage/vinoteca/promo3.webp',
            ],
        ];

        foreach ($models as $model) {
            
            $model['user_id'] = config('app.USER_ID');

            $promo = PromocionVinoteca::create([
                'online'            => 1,
                'name'              => $model['name'],
                'cost'              => $model['cost'],
                'final_price'       => $model['final_price'],
                'stock'             => $model['stock'],
                'address_id'        => $model['address_id'],
                'description'       => $model['description'],
                'user_id'           => $model['user_id'],
            ]);

            PromocionVinotecaHelper::attach_articles($promo, $model['articles']);


            Image::create([
                'imageable_type'                            => 'promocion_vinoteca',
                'imageable_id'                              => $promo->id,
                env('IMAGE_URL_PROP_NAME', 'image_url')     => $model['image'],
            ]);
            Image::create([
                'imageable_type'                            => 'promocion_vinoteca',
                'imageable_id'                              => $promo->id,
                env('IMAGE_URL_PROP_NAME', 'image_url')     => env('APP_IMAGES_URL').'storage/vinoteca/trumpeter.webp',
            ]);
        }
    }
}
