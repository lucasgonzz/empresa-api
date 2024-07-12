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
        $user = User::where('company_name', 'Autopartes Boxes')->first();
        
        $models = [
            [
                'current_acount_payment_method_id'  => 2,
            ],
            [
                'current_acount_payment_method_id'  => 5,
            ],
        ];

        foreach ($models as $model) {
            $model['user_id'] = $user->id;
            AfipSelectedPaymentMethod::create($model);
        }
    }
}
