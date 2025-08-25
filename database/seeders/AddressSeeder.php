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
        } else if (env('FOR_USER') == 'bad_girls') {
            $this->bad_girls();
        } else {
            $this->default();
        }
    }

    function bad_girls() {

        $models = [
            [
                'num'       => 1,
                'street'    => 'Arriba',
                'user_id'   => env('USER_ID'),
            ],
            [
                'num'       => 2,
                'street'    => 'Abajo',
                'default_address'    => 1,
                'user_id'   => env('USER_ID'),
            ],
        ];
        foreach ($models as $model) {
            Address::create($model);
        }
    }

    function default() {

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
        ];
        foreach ($models as $model) {
            Address::create($model);
        }
    }
}
