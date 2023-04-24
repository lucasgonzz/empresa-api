<?php

namespace Database\Seeders;

use App\Models\Address;
use Illuminate\Database\Seeder;

class AddressSeeder extends Seeder
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
                'num'       => 1,
                'street'    => 'San antonio 332',
                'user_id'   => 1,
            ],
            [
                'num'       => 2,
                'street'    => 'San martin 221',
                'user_id'   => 1,
            ],
            [
                'num'       => 2,
                'street'    => 'San martin 221',
                'user_id'   => 1,
            ],
            [
                'street'    => 'San martin 221',
                'city'      => 'Coronel Pringles',
                'province'  => 'San Luis',
                'lat'       => '-37.98283990485',
                'lng'       => '-61.347817694165',
                'buyer_id'  => 1,
            ],
        ];
        foreach ($models as $model) {
            Address::create($model);
        }
    }
}
