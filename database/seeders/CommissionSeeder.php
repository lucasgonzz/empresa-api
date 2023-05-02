<?php

namespace Database\Seeders;

use App\Models\Commission;
use App\Models\User;
use Illuminate\Database\Seeder;

class CommissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $user = User::where('company_name', 'lucas')->first();
        $models = [
            // Juguetes
            [
                'num'               => 1,
                'from'              => 0,
                'until'             => 5,
                'amount'            => 10,
                'sale_type_id'      => 1,
                'user_id'           => $user->id,
            ],
            [
                'num'               => 2,
                'from'              => 5,
                'until'             => 10,
                'amount'            => 10,
                'sale_type_id'      => 1,
                'user_id'           => $user->id,
            ],
            [
                'num'               => 1,
                'from'              => 10,
                'until'             => 20,
                'amount'            => 7,
                'sale_type_id'      => 1,
                'user_id'           => $user->id,
            ],
            [
                'num'               => 1,
                'from'              => 20,
                'until'             => 25,
                'amount'            => 7,
                'sale_type_id'      => 1,
                'user_id'           => $user->id,
            ],
            [
                'num'               => 1,
                'from'              => 25,
                'until'             => 100,
                'amount'            => 5,
                'sale_type_id'      => 1,
                'user_id'           => $user->id,
            ],

            // Varios
            [
                'num'               => 1,
                'from'              => 0,
                'until'             => 5,
                'amount'            => 5,
                'sale_type_id'      => 2,
                'user_id'           => $user->id,
            ],
            [
                'num'               => 2,
                'from'              => 5,
                'until'             => 10,
                'amount'            => 5,
                'sale_type_id'      => 2,
                'user_id'           => $user->id,
            ],
            [
                'num'               => 1,
                'from'              => 10,
                'until'             => 15,
                'amount'            => 5,
                'sale_type_id'      => 2,
                'user_id'           => $user->id,
            ],
            [
                'num'               => 1,
                'from'              => 15,
                'until'             => 100,
                'amount'            => 3,
                'sale_type_id'      => 2,
                'user_id'           => $user->id,
            ],
        ];

        foreach ($models as $model) {
            Commission::create($model);
        }
    }
}
