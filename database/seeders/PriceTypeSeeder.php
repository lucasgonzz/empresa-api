<?php

namespace Database\Seeders;

use App\Models\PriceType;
use App\Models\User;
use Illuminate\Database\Seeder;

class PriceTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $for_user = env('FOR_USER');
        $user = User::where('company_name', 'Autopartes Boxes')->first();
        $models = [
            [
                'num'           => 1,
                'name'          => 'Mayorista',
                'percentage'    => 10,
                'position'      => 1,
                'user_id'       => $user->id,
            ],
            [
                'num'           => 2,
                'name'          => 'Comercio',
                'percentage'    => 50,
                'position'      => 2,
                'user_id'       => $user->id,
            ],
            [
                'num'           => 3,
                'name'          => 'Consumidor final',
                'percentage'    => 100,
                'position'      => 3,
                'user_id'       => $user->id,
            ],
        ];
        foreach ($models as $model) {

            if ($for_user == 'Colman') {
                $model['percentage'] = 100;
            }

            PriceType::create($model);
        }
    }
}
