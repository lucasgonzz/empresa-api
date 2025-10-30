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
        'provider_id'       => 2,
        'images'            => [
            [
                'url'       => get_image('storage/supermercado/yerba.webp'),
            ],
        ],
    ],

    [
        'featured'          => null,
        'bar_code'          => '002',
        'provider_code'     => 'p-002',
        'iva_id'            => 2,
        'name'              => 'Mate Torpedo',
        'stock'             => null,
        'cost'              => 1000,
        'sub_category_name' => 'Mates',
        'provider_id'       => 3,
        'images'            => [
            [
                'url'       => get_image('storage/supermercado/matetorpedo.webp'),
            ],
        ],
        'addresses'     => [
            [
                'id'            => 1,
                'amount'        => 50,
                'min'           => 10,
                'max'           => 100,
            ],
            [
                'id'            => 2,
                'amount'        => 50,
                'min'           => 50,
                'max'           => 100,
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
        'iva_id'            => 2,
        'name'              => 'Fanta',
        'stock'             => null,
        'cost'              => 1.5,
        'sub_category_name' => 'Cocacola',
        'percentage_gain'   => null,
        'apply_provider_percentage_gain'    => 1,
        'provider_id'       => 1,
        'cost_in_dollars'   => 1,
        'images'            => [
            [
                'url'       => get_image('storage/supermercado/fanta.webp'),
            ],
        ],
        'addresses'     => [
            [
                'id'            => 1,
                'amount'        => 50,
                'min'           => 10,
                'max'           => 100,
            ],
            [
                'id'            => 2,
                'amount'        => 50,
                'min'           => 50,
                'max'           => 100,
            ],
        ],
    ],

    [
        'featured'          => null,
        'bar_code'          => '5449000054227',
        'provider_code'     => 'p-003',
        'iva_id'            => 2,
        'name'              => 'Coca Cola 2L',
        'stock'             => null,
        'cost'              => 1.5,
        'sub_category_name' => 'Manaos',
        'percentage_gain'   => null,
        'apply_provider_percentage_gain'    => 1,
        'cost_in_dollars'   => 1,
        'provider_id'       => 1,
        'images'            => [
            [
                'url'       => get_image('storage/supermercado/cocacola.webp'),
            ],
        ],
        'addresses'     => [
            [
                'id'            => 1,
                'amount'        => 50,
                'min'           => 10,
                'max'           => 100,
            ],
            [
                'id'            => 2,
                'amount'        => 50,
                'min'           => 50,
                'max'           => 100,
            ],
        ],
    ],
    

    [
        'featured'          => null,
        'bar_code'          => '7622201735364',
        'provider_code'     => 'p-221132',
        'iva_id'            => 2,
        'name'              => 'Jugo tang',
        'stock'             => null,
        'cost'              => 100,
        'sub_category_name' => 'Manaos',
        'provider_id'       => 1,
        'images'            => [
            [
                'url'       => get_image('storage/supermercado/tang.jpg'),
            ],
        ],
        'addresses'     => [
            [
                'id'            => 1,
                'amount'        => 50,
                'min'           => 10,
                'max'           => 100,
            ],
            [
                'id'            => 2,
                'amount'        => 50,
                'min'           => 50,
                'max'           => 100,
            ],
        ],
    ],
    
];


function get_image($url) {
    if (env('APP_ENV') == 'production') {
        return env('APP_IMAGES_URL').'/'.$url;
    } else {
        return env('APP_IMAGES_URL').$url;
    }
}