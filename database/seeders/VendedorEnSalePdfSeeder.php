<?php

namespace Database\Seeders;

use App\Models\ExtencionEmpresa;
use Illuminate\Database\Seeder;

class VendedorEnSalePdfSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        ExtencionEmpresa::create([
            'name' => 'vendedor_en_sale_pdf',
            'slug' => 'vendedor_en_sale_pdf',
        ]);
    }
}
