<?php

$articles = [
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
                'amount'    => 5,
            ],
            [
                'id'        => 2,
                'amount'    => 5,
            ],
        ],
    ],
    [
        'bar_code'          => '',
        'provider_code'     => '',
        'name'              => 'Pata de cama',
        'stock'             => 100,
        'cost'              => 50,
        'price'             => 100,
        'sub_category_name' => 'Comedor',
        'provider_id'       => $rosario->id,
        'images'            => [
            [
                'url'       => 'pata-de-cama.jpg',
            ],
        ],
        // 'addresses'     => [
        //     [
        //         'id'        => 1,
        //         'amount'    => 50,
        //     ],
        //     [
        //         'id'        => 2,
        //         'amount'    => 50,
        //     ],
        // ],
    ],
    [
        'bar_code'          => '',
        'provider_code'     => '',
        'name'              => 'Marco para cama',
        'stock'             => 100,
        'cost'              => 50,
        'price'             => 1000,
        'sub_category_name' => 'Comedor',
        'provider_id'       => $rosario->id,
        'images'            => [
            [
                'url'       => 'marco-cama.jpg',
            ],
        ],
        'addresses'     => [
            [
                'id'        => 1,
                'amount'    => 50,
            ],
            [
                'id'        => 2,
                'amount'    => 50,
            ],
        ],
    ],
    [
        'bar_code'          => '',
        'provider_code'     => '',
        'name'              => 'Clavos N° 2',
        'stock'             => 100,
        'cost'              => 50,
        'price'             => 500,
        'sub_category_name' => 'Comedor',
        'provider_id'       => $rosario->id,
        'images'            => [
            [
                'url'       => 'clavos.jpg',
            ],
        ],
        'addresses'     => [
            [
                'id'        => 1,
                'amount'    => 50,
            ],
            [
                'id'        => 2,
                'amount'    => 50,
            ],
        ],
    ],
    [
        'bar_code'          => '',
        'provider_code'     => '',
        'name'              => 'Pintura para cama',
        'stock'             => 100,
        'cost'              => 50,
        'price'             => 500,
        'sub_category_name' => 'Comedor',
        'provider_id'       => $rosario->id,
        'images'            => [
            [
                'url'       => 'pintura.jpg',
            ],
        ],
        'addresses'     => [
            [
                'id'        => 1,
                'amount'    => 50,
            ],
            [
                'id'        => 2,
                'amount'    => 50,
            ],
        ],
    ],
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
        // 'default_in_vender' => 1,
        'iva_id'            => 6,
        'images'            => [
            [
                'url'       => 'martillo.jpg',
            ],
        ],
    ],
];
