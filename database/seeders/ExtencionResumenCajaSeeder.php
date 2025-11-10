<?php

namespace Database\Seeders;

use App\Models\ExtencionEmpresa;
use Illuminate\Database\Seeder;

class ExtencionResumenCajaSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        ExtencionEmpresa::create([
            'name' => 'Resumen Caja',
            'slug' => 'resumen_caja',
        ]);
    }
}
