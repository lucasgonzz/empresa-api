<?php

namespace Database\Seeders;

use App\Models\SaleStatus;
use Illuminate\Database\Seeder;

class SaleStatusSeeder extends Seeder
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
                'name'      => 'Diseño',
                'description'   => 'Esta con los diseñadores',
            ],
            [   
                'name'      => 'Estampado',
                'description'   => '',
            ],
            [   
                'name'      => 'Empaquetado',
                'description'   => '',
            ],
        ];

        $position = 0;
        foreach ($models as $model) {
            $position++;
            $model['position'] = $position;
            $model['user_id'] = config('app.USER_ID');
            SaleStatus::create($model);
        }
    }
}
