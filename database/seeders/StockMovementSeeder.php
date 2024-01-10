<?php

namespace Database\Seeders;

use App\Http\Controllers\StockMovementController;
use Illuminate\Database\Seeder;

class StockMovementSeeder extends Seeder
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
                'model_id'          => 16,
                'from_address_id'   => 1,
                'amount'            => 4,
                'provider_id'       => 1,
                'concepto'          => 'Venta N° 12',
            ],
            [
                'model_id'          => 16,
                'to_address_id'     => 2,
                'amount'            => 10,
                'provider_id'       => 1,
                'concepto'          => 'Compra a proveedor',
            ],
            [
                'model_id'          => 16,
                'from_address_id'   => 1,
                'amount'            => 2,
                'provider_id'       => 1,
                'concepto'          => 'Pedido tienda online N° 22',
            ],
            [
                'model_id'          => 16,
                'from_address_id'   => 1,
                'to_address_id'     => 2,
                'amount'            => 3,
                'provider_id'       => 1,
                'concepto'          => 'Movimiento de depositos',
            ],
        ];

        foreach ($models as $model) {
            $request = new \Illuminate\Http\Request();
            $request->model_id              = $model['model_id'];        
            $request->from_address_id       = isset($model['from_address_id']) ? $model['from_address_id'] : null;   
            $request->to_address_id         = isset($model['to_address_id']) ? $model['to_address_id'] : null;     
            $request->amount                = $model['amount'];            
            $request->provider_id           = $model['provider_id'];       
            $request->concepto              = $model['concepto'];           
            $ct = new StockMovementController();
            $ct->store($request);
        }
    }
}
