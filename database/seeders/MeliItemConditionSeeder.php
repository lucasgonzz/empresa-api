<?php

namespace Database\Seeders;

use App\Models\MeliItemCondition;
use Illuminate\Database\Seeder;

class MeliItemConditionSeeder extends Seeder
{
    /**
     * Condiciones del ítem en Mercado Libre.
     *
     * Idempotente desde la misión mercado-libre-integracion (27/10/2026): `updateOrCreate` por
     * `meli_id` (ver `MeliListingTypeSeeder`). "Reacondicionado" se mantiene, pero ojo: ML exige
     * una garantía de 90 días o más para publicarlo.
     *
     * @return void
     */
    public function run()
    {
        $models = [
            [
                'meli_id' => 'new',
                'name'    => 'Nuevo',
            ],
            [
                'meli_id' => 'used',
                'name'    => 'Usado',
            ],
            [
                'meli_id' => 'reconditioned',
                'name'    => 'Reacondicionado',
            ],
        ];

        foreach ($models as $model) {
            MeliItemCondition::updateOrCreate(['meli_id' => $model['meli_id']], ['name' => $model['name']]);
        }
    }
}
