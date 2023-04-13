<?php

namespace Database\Seeders;

use App\Models\OnlinePriceType;
use Illuminate\Database\Seeder;

class OnlinePriceTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $models = [
            [
                'name' => 'Cualquiera que ingrese a la Web',
                'slug' => 'all',
            ],
            [
                'name' => 'Solo los usuarios registrados',
                'slug' => 'only_registered',
            ],
            [
                'name' => 'Solo los usuarios registrados que esten vinculados a un Cliente del sistema',
                'slug' => 'only_buyers_with_comerciocity_client',
            ],
        ];
        foreach ($models as $model) {
            OnlinePriceType::create($model);
        }
    }
}
