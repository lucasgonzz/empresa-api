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
                // id 8
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
                // id 14
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
            [
                'name'  => 'Mercado Libre',
            ],
            [
                'name'  => 'Ajuste Insumo de produccion',
            ],
            [
                // Tanda correctivos 2408, ítem 7: rastro del stock que tenía una sucursal
                // al eliminarla (AddressController::destroy). Para bases de producción
                // existentes está ConceptoStockMovementEliminacionDeSucursalSeeder.
                'name'  => 'Eliminacion de sucursal',
            ],

        ];

        foreach ($models as $model) {
            ConceptoStockMovement::create($model);
        }



    }
}
