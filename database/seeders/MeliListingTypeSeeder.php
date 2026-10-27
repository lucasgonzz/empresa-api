<?php

namespace Database\Seeders;

use App\Models\MeliListingType;
use Illuminate\Database\Seeder;

class MeliListingTypeSeeder extends Seeder
{
    /**
     * Tipos de publicación de Mercado Libre Argentina.
     *
     * Idempotente desde la misión mercado-libre-integracion (27/10/2026): `updateOrCreate` por
     * `meli_id`, para que correrlo dos veces (o después de la migración
     * `asegurar_catalogos_de_mercado_libre`) no duplique filas. Los nombres son los que ML usa hoy
     * en el frente: "Clásica" pasó a ser "sin cuotas" y "Premium", "con cuotas".
     *
     * @return void
     */
    public function run()
    {
        $models = [
            [
                'meli_id' => 'gold_pro',
                'name'    => 'Premium (con cuotas)',
            ],
            [
                'meli_id' => 'gold_special',
                'name'    => 'Clásica (sin cuotas)',
            ],
            [
                'meli_id' => 'free',
                'name'    => 'Gratuita',
            ],
        ];

        foreach ($models as $model) {
            MeliListingType::updateOrCreate(['meli_id' => $model['meli_id']], ['name' => $model['name']]);
        }
    }
}
