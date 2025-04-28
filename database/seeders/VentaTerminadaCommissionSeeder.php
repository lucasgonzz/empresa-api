<?php

namespace Database\Seeders;

use App\Models\VentaTerminadaCommission;
use Illuminate\Database\Seeder;

class VentaTerminadaCommissionSeeder extends Seeder
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
                'monto_fijo'    => 5000,
                'seller_id'     => 2
            ],
            [
                'monto_fijo'    => 5000,
                'seller_id'     => 3
            ],
        ];

        foreach ($models as $model) {
            
            $model['user_id'] = env('USER_ID');

            VentaTerminadaCommission::create($model);
        }
    }
}
