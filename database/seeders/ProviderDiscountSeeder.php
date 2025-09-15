<?php

namespace Database\Seeders;

use App\Models\ProviderDiscount;
use Illuminate\Database\Seeder;

class ProviderDiscountSeeder extends Seeder
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
                'percentage'    => 10,
                'provider_id'   => 1
            ],
            [
                'percentage'    => 5,
                'provider_id'   => 1
            ],
        ];

        foreach ($models as $model) {
            ProviderDiscount::create($model);
        }
    }
}
