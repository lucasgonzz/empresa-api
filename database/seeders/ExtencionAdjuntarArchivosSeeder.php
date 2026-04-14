<?php

namespace Database\Seeders;

use App\Models\ExtencionEmpresa;
use Illuminate\Database\Seeder;

class ExtencionAdjuntarArchivosSeeder extends Seeder
{
    public function run()
    {
        ExtencionEmpresa::create([
            'name' => 'Adjuntar archivos a articulos en las ventas',
            'slug' => 'adjuntar_archivos_en_vantas',
        ]);
    }
}
