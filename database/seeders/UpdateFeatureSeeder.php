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
                'name' => 'Agregar un limite de dias para los PEDIDOS A PROVEEDOR',
                'description' => 'En cada pedido a proveedor, se podra indicar el dato de X dias a partir de los cuales, el sistema va a mostrar una alerta si el pedido no ha cambiado al estado de recibido.',
            ],
            [
                'name' => 'Stock para cada DIRECCION',
                'description' => 'A demas del dato de stock de un articulo, se podra indicar un stock espesifico para cada direccion dada de alta en el sistema, del cual se descontara la cantidad vendida cuando se indique la direccion en una venta.',
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
