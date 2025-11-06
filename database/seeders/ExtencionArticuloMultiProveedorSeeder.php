<?php

namespace Database\Seeders;

use App\Models\ExtencionEmpresa;
use Illuminate\Database\Seeder;

class ExtencionArticuloMultiProveedorSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        ExtencionEmpresa::create([
            'name' => 'Articulo con multiple proveedores',
            'slug' => 'articulo_multi_proveedor',
        ]);
    }
}
