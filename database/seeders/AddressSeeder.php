<?php

namespace Database\Seeders;

use App\Models\Address;
use App\Models\User;
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

        if (env('FOR_USER') == 'fenix') {
            return;
        }
        $models = [
            [
                'num'       => 1,
                'street'    => 'Tucuman',
                'user_id'   => env('USER_ID'),
            ],
            [
                'num'       => 2,
                'street'    => 'Santa Fe',
                'default_address'    => 1,
                'user_id'   => env('USER_ID'),
            ],
            [
                'num'       => 3,
                'street'    => 'Buenos Aires',
                'default_address'    => 1,
                'user_id'   => env('USER_ID'),
            ],
            [
                'num'       => 4,
                'street'    => 'Mar del Plata',
                'default_address'    => 1,
                'user_id'   => env('USER_ID'),
            ],
            // [
            //     'street'    => 'San martin 221',
            //     'city'      => 'Coronel Pringles',
            //     'province'  => 'San Luis',
            //     'lat'       => '-37.98283990485',
            //     'lng'       => '-61.347817694165',
            //     'buyer_id'  => 1,
            // ],
        ];
        foreach ($models as $model) {
            Address::create($model);
        }
    }
}
