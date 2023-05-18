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
                'name' => 'Estadísticas de Insumos',
                'description' => 'Desde el LISTADO, cada articulo va a contar con un botón, al lado del botón de estadisticas, el cual abrirá una ventana indicando la cantidad de recetas que hacen uso del articulo.',
            ],
            [
                'name' => 'Correccion del CANTIDADES ACTUALES',
                'description' => 'Correccion de la funcion CANTIDADES ACTUALES en la seccion PRODUCCION/MOVIMIENTOS.'
            ],
            [
                'name' => 'Historial de Pagos en cuentas Corrientes',
                'description' => 'Agregamos la columna Info Pagos, para que podamos ver el historial de los pagos de cada movimiento. En el caso de un movimiento de pago, vamos a ver a las deudas que ese pago aporto, y en el caso de una deuda (venta o presupuesto), vamos a ver los pagos que aportaron a esa deuda.',
            ],
            [
                'name' => 'Escoger la visibilidad de los descuentos de los artículos en la Pagina Web',
                'description' => 'En los descuentos que tengamos creados para cada articulo, vamos a poder indicar si queremos que esa informacion figure o no en la Tienda Online. Por defecto la visibilidad de los descuentos en la Pagina Web esta desactivada para todos los descuentos creados hasta el momento.',
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
