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
                'name' => 'Ya viste el panel de ESTADISTICAS',
                'description' => 'Desde la seccion de CAJA, vas a poder ver las estadisticas de los articulos mas vendidos, asi como de las categorias y sub categorias mas vendidas, dentro del rango de fecha que selecciones (por defecto es desde principos de mes hasta la fecha actual). Tambien podes ver las estadisticas de las ventas de un articulo en especifico desde la seccion del listado, con los botones que estan al final a la derecha en cada fila de la tabla.',
            ],
            [
                'name' => 'Generar documentos PDF con los articulos que necesites',
                'description' => 'Cuando hagas una busqueda o actives la seleccion multiple en el LISTADO, vas a tener la opcion de generar un PDF con los articulos involucrados, donde se mostraran la image, nombre y precio de los articulos, para que puedas enviar el documento a tus clientes o le del el uso que creas conveniente.',
            ],
            [
                'name' => 'Asignar permisos de ADMINISTRADOR a los empleados',
                'description' => 'Ahora podes asignar el rol de administrador a tus empleados, para que tengan el mismo acceso a todo el sistema que el duño de la empresa.',
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
