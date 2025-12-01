<?php

namespace Database\Seeders;

use App\Models\PermissionEmpresa;
use Illuminate\Database\Seeder;

class PermissionPaymentPlanSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        PermissionEmpresa::create([
            'name'          => 'Crear Plan de pagos',
            'model_name'    => 'Plan de pagos',
            'slug'          => 'payment_plan.store',
        ]);
        PermissionEmpresa::create([
            'name'          => 'Editar Plan de pagos',
            'model_name'    => 'Plan de pagos',
            'slug'          => 'payment_plan.update',
        ]);
    }
}
