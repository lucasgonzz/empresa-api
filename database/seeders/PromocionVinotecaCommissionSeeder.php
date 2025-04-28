<?php

namespace Database\Seeders;

use App\Models\PromocionVinotecaCommission;
use Illuminate\Database\Seeder;

class PromocionVinotecaCommissionSeeder extends Seeder
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
                'seller_id'     => 1,
                'monto_fijo'    => 1000, 
            ],
            [
                'seller_id'     => 3,
                'monto_fijo'    => 1000, 
            ],
        ];

        foreach ($models as $model) {
            
            $model['user_id'] = env('USER_ID');

            PromocionVinotecaCommission::create($model);
        }
    }
}
