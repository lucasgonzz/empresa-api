<?php

namespace Database\Seeders;

use App\Models\UnidadFrecuencia;
use Illuminate\Database\Seeder;

class UnidadFrecuenciaSeeder extends Seeder
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
                'name'  => 'Dia',
                'slug'  => 'day',
            ],
            [
                'name'  => 'Semana',
                'slug'  => 'week',
            ],
            [
                'name'  => 'Mes',
                'slug'  => 'month',
            ],
            [
                'name'  => 'Año',
                'slug'  => 'year',
            ],
        ];

        foreach ($models as $model) {
            UnidadFrecuencia::create($model);
        }
    }
}
