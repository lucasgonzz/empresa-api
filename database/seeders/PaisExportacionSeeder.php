<?php

namespace Database\Seeders;

use App\Models\PaisExportacion;
use Illuminate\Database\Seeder;

class PaisExportacionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $paises = [
            ['name' => 'Argentina', 'codigo_afip' => 200],
            ['name' => 'Bolivia', 'codigo_afip' => 202],
            ['name' => 'Brasil', 'codigo_afip' => 203],
            ['name' => 'Colombia', 'codigo_afip' => 205],
            ['name' => 'Costa Rica', 'codigo_afip' => 206],
            ['name' => 'Cuba', 'codigo_afip' => 207],
            ['name' => 'Chile', 'codigo_afip' => 208],
            ['name' => 'República Dominicana', 'codigo_afip' => 209],
            ['name' => 'Ecuador', 'codigo_afip' => 210],
            // Agregar más si tenés datos confirmados
        ];

        foreach ($paises as $pais) {
            PaisExportacion::create($pais);
        }
    }
}
