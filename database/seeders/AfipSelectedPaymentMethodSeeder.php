<?php

namespace Database\Seeders;

use App\Models\AfipSelectedPaymentMethod;
use App\Models\User;
use Illuminate\Database\Seeder;

class AfipSelectedPaymentMethodSeeder extends Seeder
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
                'current_acount_payment_method_id'  => 2,
            ],
            [
                'current_acount_payment_method_id'  => 4,
            ],
            [
                'current_acount_payment_method_id'  => 5,
            ],
        ];

        foreach ($models as $model) {
            $model['user_id'] = config('app.USER_ID');
            AfipSelectedPaymentMethod::create($model);
        }
    }
}
