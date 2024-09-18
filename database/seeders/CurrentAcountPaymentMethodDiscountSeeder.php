<?php

namespace Database\Seeders;

use App\Models\CurrentAcountPaymentMethodDiscount;
use App\Models\User;
use Illuminate\Database\Seeder;

class CurrentAcountPaymentMethodDiscountSeeder extends Seeder
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
                'current_acount_payment_method_id'  => 2,
                'discount_percentage'               => 15,
            ],  
            [
                'current_acount_payment_method_id'  => 3,
                'discount_percentage'               => 20,
            ],  
            [
                'current_acount_payment_method_id'  => 4,
                'discount_percentage'               => 15,
            ],  
        ];

        foreach ($models as $model) {
            $model['user_id'] = $user->id;
            CurrentAcountPaymentMethodDiscount::create($model);
        }
    }
}
