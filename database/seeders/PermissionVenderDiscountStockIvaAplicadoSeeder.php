<?php

namespace Database\Seeders;

use App\Models\PermissionEmpresa;
use Illuminate\Database\Seeder;

/**
 * Crea permisos para los checkboxes de Vender: descontar stock y precios con IVA.
 * Ejecutar en bases existentes que ya corrieron PermissionSeeder sin estos slugs.
 */
class PermissionVenderDiscountStockIvaAplicadoSeeder extends Seeder
{
    /**
     * Inserta los permisos de discount_stock e iva_aplicado en Vender.
     *
     * @return void
     */
    public function run()
    {
        // Permisos del módulo Vender (checkboxes del remito).
        $permissions_data = [
            [
                'name' => 'Descontar stock en VENDER',
                'model_name' => 'Vender',
                'slug' => 'vender.discount_stock',
            ],
            [
                'name' => 'Usar precios con IVA en VENDER',
                'model_name' => 'Vender',
                'slug' => 'vender.iva_aplicado',
            ],
        ];

        foreach ($permissions_data as $permission_data) {
            PermissionEmpresa::create($permission_data);
        }
    }
}
