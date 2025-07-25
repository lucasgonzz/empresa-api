<?php

    
    $categories = Category::all();

    for ($i = 1; $i <= 100; $i++) {
        $category = $categories[($i - 1) % count($categories)];

        $name = "{$category} Modelo " . str_pad($i, 3, '0', STR_PAD_LEFT);
        // Genero códigos: barcode de 13 dígitos, provider_code 8 dígitos
        $barcode = str_pad((string)rand(1000000000000, 9999999999999), 13, '0', STR_PAD_LEFT);
        $provider_code = str_pad((string)rand(10000000, 99999999), 8, STR_PAD_LEFT);
        // Costo entre 500 y 5000 (ej US$ o AR$)
        $cost = rand(500, 5000);
        // Margen 10‑50%
        $percentage_gain = rand(10, 50);
        // Imágenes placeholder
        $img1 = "https://via.placeholder.com/600x800.png?text=" . urlencode($name . "+1");
        $img2 = "https://via.placeholder.com/600x800.png?text=" . urlencode($name . "+2");

        $articles[] = [
            'name'           => $name,
            'barcode'        => $barcode,
            'provider_code'  => $provider_code,
            'cost'           =>$cost
            'percentage_gain'         =>$percentage_gain
            'images'         => [
                $img1,
                $img2,
            ],
            'category'       => $category,
        ],
    }

return [
    'articles' => [
            // Defino nombre según tipo
        ?>
        <?php endfor; ?>
    ],
];
