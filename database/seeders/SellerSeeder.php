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
        $user = User::where('company_name', 'Autopartes Boxes')->first();
        $models = [
            [
                'num'                       => 1,
                'name'                      => 'Marcelo',
                'commission_after_pay_sale' => 1,
                'user_id'                   => $user->id,
            ],
            [
                'num'                       => 2,
                'name'                      => 'Matias',
                'commission_after_pay_sale' => 1,
                'user_id'                   => $user->id,
            ],
            [
                'num'                       => 3,
                'name'                      => 'Oscar (perdidas)',
                'commission_after_pay_sale' => 0,
                'user_id'                   => $user->id,
            ],
            [
                'num'                       => 4,
                'name'                      => 'Oscar',
                'commission_after_pay_sale' => 0,
                'user_id'                   => $user->id,
            ],
            [
                'num'                       => 5,
                'name'                      => 'Fede',
                'commission_after_pay_sale' => 0,
                'user_id'                   => $user->id,
            ],
            [
                'num'                       => 6,
                'name'                      => 'Papi',
                'commission_after_pay_sale' => 0,
                'user_id'                   => $user->id,
            ],
        ];

        foreach ($models as $model) {
            Seller::create($model);
        }
    }
}
