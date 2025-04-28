<?php

namespace Database\Seeders;

use App\Models\ConceptoStockMovement;
use Illuminate\Database\Seeder;

class ConceptoStockMovementSeeder extends Seeder
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
                'name'  => 'Ingreso manual',
            ],

            // Reseteo de stock
            [
                'name'  => 'Reseteo de Stock',
            ],

            // Ventas
            [
                'name'  => 'Venta',
            ],
            [
                'name'  => 'Act Venta',
            ],
            [
                'name'  => 'Se elimino de la venta',
            ],
            [
                'name'  => 'Se elimino la venta',
            ],


            // Notas de credito
            [
                'name'  => 'Nota de credito',
            ],


            // Compras
            [
                'name'  => 'Compra a proveedor',
            ],
            [
                'name'  => 'Act Compra a proveedor',
            ],


            // Depositos
            [
                'name'  => 'Creacion de deposito',
            ],
            [
                'name'  => 'Actualizacion de deposito',
            ],
            [
                'name'  => 'Mov entre depositos',
            ],
            [
                'name'  => 'Mov manual entre depositos',
            ],

            // Pedido online
            [
                'name'  => 'Pedido Online',
            ],


            // Excel
            [
                'name'  => 'Importacion de excel',
            ],

            // Produccion
            [
                'name'  => 'Insumo de produccion',
            ],
            [
                'name'  => 'Produccion',
            ],

            // Voy agregando los ultimos aca
            [
                'name'  => 'Eliminacion Compra a proveedor',
            ],
            [
                'name'  => 'Creacion de Promocion',
            ],
            [
                'name'  => 'Eliminacion de Promocion',
            ],

        ];

        foreach ($models as $model) {
            ConceptoStockMovement::create($model);
        }



    }
}
