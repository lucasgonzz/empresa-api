<?php

namespace Database\Seeders;

use App\Models\PermissionEmpresa;
use Illuminate\Database\Seeder;

class PermissionInfoFacturacion extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        PermissionEmpresa::create([
            'name'          => 'Ver informacion de facturacion',
            'model_name'    => 'Reportes',
            'slug'          => 'reportes.info_facturacion',
        ]);
    }
}
