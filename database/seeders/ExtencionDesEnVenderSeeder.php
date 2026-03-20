<?php

namespace Database\Seeders;

use App\Models\ExtencionEmpresa;
use Illuminate\Database\Seeder;

class ExtencionDesEnVenderSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        ExtencionEmpresa::create([
            'name' => 'Buscar por descripcion en VENDER',
            'slug' => 'search_descripcion_en_vender',
        ]);
    }
}
