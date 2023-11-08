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
                'name' => 'Movimientos de Stock',
                'description' => 'Sumamos una nueva instancia a la hora de trabajar con el stock de los articulos, resumimos lo mas importante en un video. Si no lo has visto, comunicate con tu empleador para que te facilite el link.',
            ],
            [
                'name' => 'Pedidos Online HISTORICOS',
                'description' => 'En estas secciones, se podran seguir visualizando los resultados filtrados por una fecha, o se podra ver el historial completo todo junto. Esta opcion aparece en la isquina superior derecha.',
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
