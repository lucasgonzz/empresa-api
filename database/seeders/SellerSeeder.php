<?php

namespace Database\Seeders;

use App\Models\Seller;
use App\Models\User;
use Illuminate\Database\Seeder;

class SellerSeeder extends Seeder
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
            [
                'num'       => 1,
                'name'      => 'Marcelo',
                'user_id'   => $user->id,
            ],
            [
                'num'       => 2,
                'name'      => 'Matias',
                'user_id'   => $user->id,
            ],
        ];

        foreach ($models as $model) {
            Seller::create($model);
        }
    }
}
