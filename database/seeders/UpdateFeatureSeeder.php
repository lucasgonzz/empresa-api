<?php

namespace Database\Seeders;

use App\Models\UpdateFeature;
use Illuminate\Database\Seeder;

class UpdateFeatureSeeder extends Seeder
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
                'name' => 'Nuevo diseño para las Tablas',
                'description' => 'Adaptamos las tablas para que tengan la fila de los titulos fija para una mejor visualizacion de los datos',
            ],
        ];
        foreach ($models as $model) {
            UpdateFeature::create([
                'name'          => $model['name'],
                'description'   => $model['description'],
            ]);
        }
    }
}
