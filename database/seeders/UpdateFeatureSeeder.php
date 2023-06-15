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
                'name' => 'Pedidos a Proveedor',
                'description' => 'Cuando se agregue un articulo al pedido de un proveedor, se va a poder indicar si queremos que se sume al listado.',
            ],
            [
                'name' => 'Configuracion Online',
                'description' => 'Se separo el apartado de configuracion de la Tienda y la configuracion general de la cuenta',
            ],
            [
                'name' => 'Boton checkear saldos',
                'description' => 'Para depejar cualquier duda en la suma de los saldos de las cuentas corrientes, se agrego el boton "checkear saldos" para recalcular los saldos',
            ],
            [
                'name' => 'Mensajes Online',
                'description' => 'Se mejoro la vista de los Mensajes de la Tienda Online',
            ],
            [
                'name' => 'Notas de credito AFIP',
                'description' => 'Ahora se pueden enviar a AFIP las notas de credito generadas para una venta que tambien haya sido blanqueada',
            ],
            [
                'name' => 'Seleccionar con un click resultado de busqueda',
                'description' => 'El primer resultado de busqueda, que ya aparece marcado, puede ser seleccionado con el mouse sin deseleccionarce como ocurria antes.',
            ],
            // [
            //     'name' => '',
            //     'description' => '',
            // ],
        ];
        foreach ($models as $model) {
            UpdateFeature::create([
                'name'          => $model['name'],
                'description'   => $model['description'],
            ]);
        }
    }
}
