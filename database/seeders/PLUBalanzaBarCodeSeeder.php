<?php

namespace Database\Seeders;

use App\Models\ExtencionEmpresa;
use Illuminate\Database\Seeder;

class PLUBalanzaBarCodeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        ExtencionEmpresa::create([
            'name' => 'PLU Balanza codigos de barra',
            'slug' => 'plu_balanza_bar_code',
        ]);
    }
}
