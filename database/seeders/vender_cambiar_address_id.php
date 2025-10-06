<?php

namespace Database\Seeders;

use App\Models\PermissionEmpresa;
use Illuminate\Database\Seeder;

class vender_cambiar_address_id extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        PermissionEmpresa::create([
            'name'      => 'Cambiar la sucursal/deposito en Vender',
            'model_name'        => 'Vender',
            'slug'            => 'vender.cambiar_address_id',
        ]);
    }
}
