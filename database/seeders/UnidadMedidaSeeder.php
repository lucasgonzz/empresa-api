<?php

namespace Database\Seeders;

use App\Models\UnidadMedida;
use Illuminate\Database\Seeder;

class UnidadMedidaSeeder extends Seeder
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
                'name'  => 'Unidad',
            ],
            [
                'name'  => 'Gramo',
            ],
            [
                'name'  => 'Kilo',
            ],
            [
                'name'  => 'Litro',
            ],
            [
                'name'  => 'Centimetro',
            ],
            [
                'name'  => 'Metro',
            ],
            [
                'name'  => 'Rollo',
            ],
            [
                'name'  => 'Par',
            ],
        ];

        foreach ($models as $model) {
            UnidadMedida::create($model);
        }
    }
}
