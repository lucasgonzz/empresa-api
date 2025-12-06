<?php

namespace Database\Seeders;

use App\Models\PermissionEmpresa;
use Illuminate\Database\Seeder;

class prohibir_eliminar_articulos_de_venta_seeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        PermissionEmpresa::create([
            'name'          => 'Prohibir eliminar articulo de una venta sin autorizacion',
            'model_name'    => 'Vender',
            'slug'          => 'vender.prohibir_eliminar_articulos_de_venta',
        ]);
    }
}
