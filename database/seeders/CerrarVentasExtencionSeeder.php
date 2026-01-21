<?php

namespace Database\Seeders;

use App\Models\ExtencionEmpresa;
use Illuminate\Database\Seeder;

class CerrarVentasExtencionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        ExtencionEmpresa::create([
            'name' => 'Cerrar ventas (para luego no poder seguir actualizandolas)',
            'slug' => 'cerrar_ventas',
        ]);
    }
}
