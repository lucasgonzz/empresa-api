<?php

namespace Database\Seeders;

use App\Models\ExtencionEmpresa;
use Illuminate\Database\Seeder;

class providers_article_price_from_costo_mas_iva_seeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        ExtencionEmpresa::create([
            'name' => 'Setear Precios de articulos con COSTO + IVA',
            'slug' => 'providers_article_price_from_costo_mas_iva',
        ]);
    }
}
