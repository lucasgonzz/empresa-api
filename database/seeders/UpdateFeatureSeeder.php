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
                'name' => 'Boton Tareas',
                'description' => 'En la ezquina superior derecha, a la izquierda del nombre de usuario, habra un boton azul con el icono de mensaje, para que se puedan dejar agendadas tareas para uno a mas usuarios. Las tareas pueden ser agendadas tanto por el dueño como por los empleados. El dueño podra ver todas las tareas, hayan sido o no creadas para el, mientras que los empleados solo podran ver las tareas creadas por o para ellos. Tambien se podra indicar si se termino o no la tarea.',
            ],
            [
                'name'  => 'Pantalla de carga en segundo plano',
                'description'   => 'Anteriormente habia que esperar que el sistema descargue todos los recursos de la nube para poder comenzar a utilizarlo, a partir de ahora este proceso ocurrira en segundo plano para que se pueda comenzar a utilizar el sistema lo mas rapido posible.'
            ],
            [
                'name'  => 'Optimizacion en dispositivos mobiles',
                'description'   => 'Al ingresar desde un dispositivo mobil no se descargara ingormacion que consuman muchos recursos, como es el caso de los articulos, clientes y proveedores. Cuando se necesite hacer uso de esta informacion, el sistema los obtendra mediante una solicitud al servidor en el momento en que se necesite. Si igual prefiere tener descargada esta informacion, puede descargarlos de todas formas.'
            ],
            [
                'name'  => 'Seter el costo de un articulo en base a su reseta',
                'description'   => 'En las recetas aparece la opcion de "Establecer costo del articulo en base a los costos de los insumos", si se marca esta opcion, el costo del articulo se calculara sumando los costos de los insumos utilizados para producirlo.'
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
