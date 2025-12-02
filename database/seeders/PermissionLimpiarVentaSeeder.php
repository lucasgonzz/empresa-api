<?php

namespace Database\Seeders;

use App\Models\PermissionEmpresa;
use Illuminate\Database\Seeder;

class PermissionLimpiarVentaSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        PermissionEmpresa::create([
            'name'          => 'Usar boton de limpiar venta',
            'model_name'    => 'Vender',
            'slug'          => 'vender.limpiar_venta',
        ]);
    }
}
