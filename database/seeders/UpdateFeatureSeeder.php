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
                'name' => 'Preguntar la cantidad en VENDER',
                'description' => 'Desde la configuracion se va a poder indicar si se quiere que pregunte la cantidad del articulo que se este por agregar al remito en VENDER, si se deja desactivado, el articulo se agregara automaticamente con la cantidad = 1.',
            ],
            [
                'name' => 'Nuevos permisos',
                'description' => 'Se agrego el permiso "Crear un articulo no ingresado en VENDER", para indicar si el empleado podra crear un articulo no ingresado al sistema desde VENDER.',
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
