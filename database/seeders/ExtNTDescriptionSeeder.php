<?php

namespace Database\Seeders;

use App\Models\ExtencionEmpresa;
use Illuminate\Database\Seeder;

class ExtNTDescriptionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        
        ExtencionEmpresa::create([
            'name' => 'Descripciones en Notas de credito',
            'slug' => 'nota_credito_descriptions',
        ]);
    }
}
