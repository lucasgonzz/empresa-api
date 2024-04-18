<?php 

$articles = [];

for ($num=1; $num <= 30 ; $num++) { 
    $article = [
        'featured'          => null,
        'bar_code'          => $num,
        'provider_code'     => 'prov-'.$num,
        'name'              => 'Articulo '.$num,
        'stock'             => 100,
        'cost'              => 2000,
        // 'price'             => 3000,
        'sub_category_name' => 'Herramientas',
        'provider_id'       => 1,
        'images'            => [
            [
                'url'       => env('APP_URL').'/storage/auto-partes/pinza.jpg',
            ],
        ],
    ];

    $articles[] = $article;
}

return $articles;