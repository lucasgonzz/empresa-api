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
                'name' => 'Buscador de codigo de barras',
                'description' => 'Desde el LISTADO, vas a podre buscar los articulos diractamente por su codigo de barras con el atajo que vas a encontrar en la parte superior de la pantalla, a la derecha de los botones de informacion de stock.',
            ],
            [
                'name' => 'Mejora en los buscadores',
                'description' => 'Ahora cuando se busque algun dato, como un articulo por su nombre desde VENDER, no hace falta colocar el nombre con las palabras en el mismo orden, ahora el sistema reconocera el dato a buscar con cualquier orden de palabras, siempre y cuando el articulo contenga esas palabras.',
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
