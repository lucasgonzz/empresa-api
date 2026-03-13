<?php

namespace Database\Seeders;

use App\Models\Cuota;
use Illuminate\Database\Seeder;

class CuotaSeeder extends Seeder
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
                'cantidad_cuotas'   => 1,
                'recargo'         => 5,
            ],
            [
                'cantidad_cuotas'   => 3,
                'recargo'         => 10,
            ],
            [
                'cantidad_cuotas'   => 6,
                'recargo'         => 20,
            ],
            [
                'cantidad_cuotas'   => 9,
            ],
            [
                'cantidad_cuotas'   => 12,
            ],
        ];

        foreach ($models as $model) {
            $model['user_id'] = env('USER_ID', 500);

            Cuota::create($model);
        }
    }
}
