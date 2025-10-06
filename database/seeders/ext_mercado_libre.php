<?php

namespace Database\Seeders;

use App\Models\ExtencionEmpresa;
use Illuminate\Database\Seeder;

class ext_mercado_libre extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        ExtencionEmpresa::create([
            'name' => 'Mercado Libre',
            'slug' => 'mercado_libre',
        ]);
    }
}
