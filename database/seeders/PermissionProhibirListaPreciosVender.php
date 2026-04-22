<?php

namespace Database\Seeders;

use App\Models\PermissionEmpresa;
use Illuminate\Database\Seeder;

class PermissionProhibirListaPreciosVender extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        PermissionEmpresa::create([
            'model_name'      => 'Vender',
            'name'            => 'Prohibir cambiar la lista de precios en VENDER',
            'slug'            => 'vender.prohibir_camibar_lista_de_precios',
        ]);
    }
}
