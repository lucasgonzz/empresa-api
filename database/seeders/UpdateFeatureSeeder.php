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
                'name' => 'Imprimir lista de articulos en PDF',
                'description' => 'Desde el LISTADO, luego de realizar una busqueda o una seleccion multiple, se podra imprimir una lista en PDF con los articulos sin imagenes.',
            ],
            [
                'name' => 'Pedidos a proveedores y Presupuestos HISTORICOS',
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
