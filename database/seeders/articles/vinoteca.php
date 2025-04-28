<?php 

$articles = [


    // Categoria Almacen

    [
        'featured'          => 8,
        'bar_code'          => '001',
        'provider_code'     => 'p-001',
        'name'              => 'Elementos Malbec',
        'bodega'            => 'Elemento',
        'cepa'              => 'Malbec',
        'stock'             => 100,
        'cost'              => 1000,
        'presentacion'      => 6,
        'category_name'     => 'Vinos',
        'sub_category_name' => 'Locales',
        'provider_id'       => 1,
        'images'            => [
            [
                'url'       => _get_image_url('/storage/vinoteca/elementos.webp'),
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
        'featured'          => 8,
        'bar_code'          => '001',
        'provider_code'     => 'p-001',
        'name'              => 'Elementos Cabernet',
        'bodega'            => 'Elemento',
        'cepa'              => 'Cabernet',
        'stock'             => 100,
        'cost'              => 1000,
        'presentacion'      => 6,
        'category_name'     => 'Vinos',
        'sub_category_name' => 'Locales',
        'provider_id'       => 1,
        'images'            => [
            [
                'url'       => _get_image_url('/storage/vinoteca/elementos.webp'),
            ],
        ],
    ],


    [
        'featured'          => 8,
        'bar_code'          => '001',
        'provider_code'     => 'p-001',
        'name'              => 'Cafayate Cabernet',
        'bodega'            => 'Cafayate',
        'cepa'              => 'Cabernet',
        'stock'             => 100,
        'cost'              => 1000,
        'presentacion'      => 6,
        'category_name'     => 'Vinos',
        'sub_category_name' => 'Locales',
        'provider_id'       => 1,
        'images'            => [
            [
                'url'       => _get_image_url('/storage/vinoteca/cafayate.webp'),
            ],
        ],
    ],
    [
        'featured'          => 8,
        'bar_code'          => '001',
        'provider_code'     => 'p-001',
        'name'              => 'Cafayate Malbec',
        'bodega'            => 'Cafayate',
        'cepa'              => 'Malbec',
        'stock'             => 100,
        'cost'              => 1000,
        'presentacion'      => 6,
        'category_name'     => 'Vinos',
        'sub_category_name' => 'Locales',
        'provider_id'       => 1,
        'images'            => [
            [
                'url'       => _get_image_url('/storage/vinoteca/cafayate.webp'),
            ],
        ],
    ],

    [
        'featured'          => 8,
        'bar_code'          => '001',
        'provider_code'     => 'p-001',
        'name'              => 'Trumpeter Malbec',
        'bodega'            => 'Trumpeter',
        'cepa'              => 'Malbec',
        'stock'             => 100,
        'cost'              => 1000,
        'presentacion'      => 6,
        'category_name'     => 'Vinos',
        'sub_category_name' => 'Importados',
        'provider_id'       => 1,
        'images'            => [
            [
                'url'       => _get_image_url('/storage/vinoteca/trumpeter.webp'),
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
        'featured'          => 8,
        'bar_code'          => '001',
        'provider_code'     => 'p-001',
        'name'              => 'Trumpeter Cabernet',
        'bodega'            => 'Trumpeter',
        'cepa'              => 'Cabernet',
        'stock'             => 100,
        'cost'              => 1000,
        'presentacion'      => 6,
        'category_name'     => 'Vinos',
        'sub_category_name' => 'Importados',
        'provider_id'       => 1,
        'images'            => [
            [
                'url'       => _get_image_url('/storage/vinoteca/trumpeter.webp'),
            ],
        ],
    ],


    [
        'featured'          => 8,
        'bar_code'          => '001',
        'provider_code'     => 'p-001',
        'name'              => 'Champagne Perrier-Joüet Grand Brut – Estuche de 1 x 750cc',
        'bodega'            => 'Grand Brut',
        'cepa'              => 'Grand Brut',
        'stock'             => 100,
        'cost'              => 1000,
        'presentacion'      => 6,
        'category_name'     => 'Espumantes',
        'provider_id'       => 1,
        'images'            => [
            [
                'url'       => _get_image_url('/storage/vinoteca/Champagne_perrier.webp'),
            ],
        ],
    ],
    [
        'featured'          => 8,
        'bar_code'          => '001',
        'provider_code'     => 'p-001',
        'name'              => 'Rutini Extra Brut – Caja de 6 Botellas x 750cc',
        'bodega'            => 'Rutini',
        'cepa'              => 'Extra Brut',
        'stock'             => 100,
        'cost'              => 1000,
        'presentacion'      => 6,
        'category_name'     => 'Espumantes',
        'provider_id'       => 1,
        'images'            => [
            [
                'url'       => _get_image_url('/storage/vinoteca/Champagne_rutini.webp'),
            ],
        ],
    ],



    [
        'featured'          => 8,
        'bar_code'          => '001',
        'provider_code'     => 'p-001',
        'name'              => 'Jack Daniel´s Honey',
        'stock'             => 100,
        'cost'              => 1000,
        'presentacion'      => 6,
        'category_name'     => 'Whiskies Importados',
        'provider_id'       => 1,
        'images'            => [
            [
                'url'       => _get_image_url('/storage/vinoteca/jack_daniels.webp'),
            ],
        ],
    ],
    
];


function _get_image_url($url) {
    if (env('APP_ENV') == 'production') {
        return env('APP_IMAGES_URL').'/public'.$url;
    } else {
        return env('APP_IMAGES_URL').$url;
    }
}