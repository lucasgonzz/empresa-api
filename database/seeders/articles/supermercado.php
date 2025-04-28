<?php 

$articles = [


    // Categoria Almacen

    [
        'featured'          => 8,
        'bar_code'          => '001',
        'provider_code'     => 'p-001',
        'name'              => 'Yerba',
        'stock'             => 100,
        'cost'              => 1000,
        'sub_category_name' => 'Yerbas',
        'provider_id'       => 1,
        'images'            => [
            [
                'url'       => get_image('/storage/supermercado/yerba.webp'),
            ],
        ],
    ],

    [
        'featured'          => null,
        'bar_code'          => '002',
        'provider_code'     => 'p-002',
        'iva_id'            => 3,
        'name'              => 'Mate Torpedo',
        'stock'             => null,
        'cost'              => 1000,
        'sub_category_name' => 'Mates',
        'provider_id'       => 1,
        'images'            => [
            [
                'url'       => get_image('/storage/supermercado/matetorpedo.webp'),
            ],
        ],
        'addresses'     => [
            [
                'id'            => 1,
                'amount'        => 50,
            ],
            [
                'id'            => 2,
                'amount'        => 50,
            ],
        ],
        'variants'          => [
            'article_properties'   => [
                [
                    'article_property_type' => 'Color',
                    'article_property_values'   => [
                        'Amarillo',
                        'Azul',
                    ],
                ],
                [
                    'article_property_type' => 'Talle',
                    'article_property_values'   => [
                        'XL',
                        'L',
                    ],
                ],
            ],
        ],
    ],



    // Categoria Gaseosas

    [
        'featured'          => null,
        'bar_code'          => '003',
        'provider_code'     => 'p-003',
        'iva_id'            => 3,
        'name'              => 'Fanta',
        'stock'             => null,
        'cost'              => 1000,
        'sub_category_name' => 'Cocacola',
        'provider_id'       => 1,
        'images'            => [
            [
                'url'       => get_image('/storage/supermercado/fanta.webp'),
            ],
        ],
        'addresses'     => [
            [
                'id'            => 1,
                'amount'        => 50,
            ],
            [
                'id'            => 2,
                'amount'        => 50,
            ],
        ],
    ],

    [
        'featured'          => null,
        'bar_code'          => '003',
        'provider_code'     => 'p-003',
        'iva_id'            => 3,
        'name'              => 'Lima limon',
        'stock'             => null,
        'cost'              => 1000,
        'sub_category_name' => 'Manaos',
        'provider_id'       => 1,
        'images'            => [
            [
                'url'       => get_image('/storage/supermercado/limalimon.webp'),
            ],
        ],
        'addresses'     => [
            [
                'id'            => 1,
                'amount'        => 50,
            ],
            [
                'id'            => 2,
                'amount'        => 50,
            ],
        ],
    ],
    
];


function get_image($url) {
    if (env('APP_ENV') == 'production') {
        return env('APP_IMAGES_URL').'/public'.$url;
    } else {
        return env('APP_IMAGES_URL').$url;
    }
}