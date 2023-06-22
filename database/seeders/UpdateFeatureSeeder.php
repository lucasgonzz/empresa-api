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
                'name' => 'Botones de guardar',
                'description' => 'En los formularios aparecera un boton para GUARDAR y otro para GUARDAR Y CERRAR, ambos van a guardar los cambios que hagamos, el segundo cerrara el formulario, como venia funcionando hasta el momento, mientras que el segundo mantendra abierto el formulario.',
            ],
            [
                'name' => 'Checkbox limpiar formulario',
                'description' => 'Por defecto estara activado, si lo desactivamos, luego de crear una entidad, se mantendran algunos datos para agilizar el proceso de dar de alta. Por el momento surte efecto para dar de alta los articulos, manteniendo sin limpiar los datos de: Margen de ganancia, Disponible en la tienda, Proveedor, y Aplicar margen de ganancia del proveedor',
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
