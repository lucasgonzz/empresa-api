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
                'name' => 'Ver las ventas de un articulo',
                'description' => 'Se agrego la funcionalidad para que se puedan ver las ventas hechas de un articulo, no como estadisticas, sino para explicitamente ver las ventas en las que participo el articulo y poder consultarlas desde ahi mismo. Esta funcionalidad esta disponible desde LISTADO, boton azul VENTAS (este boton esta en cada articulo).',
            ],
            [
                'name' => 'Correccion Nota de Credito',
                'description' => 'Cuando se haga una nota de credito inficando las unidades devueltas, se tendra en cuenta el descuento del articulo, si es que lo tiene, para calcular el total de la nota de credito.',
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
