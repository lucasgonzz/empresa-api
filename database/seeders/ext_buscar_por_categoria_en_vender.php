<?php

namespace Database\Seeders;

use App\Models\ExtencionEmpresa;
use Illuminate\Database\Seeder;

class ext_buscar_por_categoria_en_vender extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        ExtencionEmpresa::create([
            'name' => 'Buscar por categoria en vender',
            'slug' => 'buscar_por_categoria_en_vender',
        ]);
    }
}
