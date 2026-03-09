<?php

namespace Database\Seeders;

use App\Models\ProductionBatchMovementType;
use Illuminate\Database\Seeder;

class ProductionBatchMovementTypeSeeder extends Seeder
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
                'name'  => 'Inicio',
                'slug'  => 'start',
            ],
            [
                'name'  => 'Avance',
                'slug'  => 'advance',
            ],
            [
                'name'  => 'Envío a proveedor',
                'slug'  => 'send_to_provider',
            ],
            [
                'name'  => 'Recepción de proveedor',
                'slug'  => 'receive_from_provider',
            ],
            [
                'name'  => 'Rechazo',
                'slug'  => 'reject',
            ],
            [
                'name'  => 'Ajuste',
                'slug'  => 'adjust',
            ],
        ];

        foreach ($models as $model) {
            
            $model['user_id'] = config('app.USER_ID');

            ProductionBatchMovementType::create($model);
        }
    }
}
