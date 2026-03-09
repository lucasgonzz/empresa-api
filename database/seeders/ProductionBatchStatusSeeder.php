<?php

namespace Database\Seeders;

use App\Models\ProductionBatchStatus;
use Illuminate\Database\Seeder;

class ProductionBatchStatusSeeder extends Seeder
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
                'name'  => 'Abierto',
                'slug'  => 'open',
            ],
            [
                'name'  => 'Cerrado',
                'slug'  => 'closed',
            ],
            [
                'name'  => 'Cancelado',
                'slug'  => 'canceled',
            ],
        ];

        foreach ($models as $model) {
            
            $model['user_id'] = config('app.USER_ID');

            ProductionBatchStatus::create($model);
        }
    }
}
