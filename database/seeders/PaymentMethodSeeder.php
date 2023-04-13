<?php

namespace Database\Seeders;

use App\Models\Credential;
use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Database\Seeder;

class PaymentMethodSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $user = User::where('company_name', 'lucas')
                        ->first();
        $models = [
            [
                'name' => 'MercadoPago',
                'description' => 'Paga Online con tu cuenta de MercadoPago',
                'payment_method_type_id' => 1,
                'public_key' => 'TEST-55fdbf12-f638-48a1-a6fe-1dd41c771384',
                'access_token' => 'TEST-3668585670354328-100112-a353cb99b53860f22fdf7e7b87c4fd8b-163250661',
                'user_id'   => $user->id,
            ],
            [
                'name' => 'Contado',
                'description' => '',
                'public_key' => '',
                'access_token' => '',
                'payment_method_type_id' => null,
                'user_id'   => $user->id,
                'discount'  => 50,
            ],
            [
                'name' => 'A convenir',
                'description' => 'Podes pagar con modo, billetera Santa Fe',
                'public_key' => '',
                'access_token' => '',
                'payment_method_type_id' => null,
                'user_id'   => $user->id,
            ],
        ];
        foreach ($models as $model) {
            PaymentMethod::create($model);
        }
    }
}
