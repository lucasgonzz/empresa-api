<?php

namespace Database\Seeders;

use App\Models\MeliBuyingMode;
use Illuminate\Database\Seeder;

class MeliBuyingModeSeeder extends Seeder
{
    /**
     * Modos de compra de Mercado Libre.
     *
     * Idempotente desde la misión mercado-libre-integracion (27/10/2026): `updateOrCreate` por
     * `meli_id` (ver `MeliListingTypeSeeder`).
     *
     * @return void
     */
    public function run()
    {
        $models = [
            [
                'meli_id' => 'buy_it_now',
                'name'    => 'Compra inmediata',
            ],
        ];

        foreach ($models as $model) {
            MeliBuyingMode::updateOrCreate(['meli_id' => $model['meli_id']], ['name' => $model['name']]);
        }
    }
}
