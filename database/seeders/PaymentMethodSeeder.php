<?php

namespace Database\Seeders;

use App\Models\Credential;
use App\Models\PaymentMethod;
use App\Models\PaymentMethodInstallment;
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
        $users = User::where('company_name', 'Autopartes Boxes')
                        ->orWhere('company_name', 'Ferretodo')
                        ->get();
        $models = [
            [
                'name' => 'MercadoPago',
                'description' => 'Paga Online con tu cuenta de MercadoPago',
                'payment_method_type_id' => 1,
                'public_key' => 'TEST-4f17cb64-8711-487f-b5f3-2e363c42c717',
                'access_token' => 'TEST-8828325407758399-030617-854a278df3d3e7f4b5255a64bc6dca49-163250661',
                'surchage'  => 100,
            ],
            // [
            //     'name'          => 'Payway',
            //     'description'   => 'Paga Online con Payway',
            //     'payment_method_type_id' => 2,
            //     'public_key'    => '5HMK6GtWtUKyPhmeJo95DHtdvpJCT2G6',
            //     'access_token'  => 'WmypxaXjzOMrszu0AaW30Oc2eDn2Qj2P',
            //     'user_id'       => $user->id,
            //     'surchage'      => 100,
            //     'installments'  => [
            //         [
            //             'installments'  => 1,
            //         ],
            //         [
            //             'installments'  => 2,
            //         ],
            //         [
            //             'name'          => 'Ahora 3',
            //             'installments'  => 3,
            //         ],
            //         [
            //             'name'          => 'Ahora 6 (Solo para tarjetas de Banco)',
            //             'installments'  => 6,
            //         ],
            //     ],
            // ],
            [
                'name' => 'Contado',
                'description' => '',
                'public_key' => '',
                'access_token' => '',
                'payment_method_type_id' => null,
                'discount'  => 50,
            ],
            [
                'name' => 'A convenir',
                'description' => 'Podes pagar con modo, billetera Santa Fe',
                'public_key' => '',
                'access_token' => '',
                'payment_method_type_id' => null,
            ],
        ];
        foreach ($users as $user) {
            foreach ($models as $model) {
                $payment_method = PaymentMethod::create([
                    'name'                      => isset($model['name']) ? $model['name'] : null,
                    'description'               => isset($model['description']) ? $model['description'] : null,
                    'payment_method_type_id'    => isset($model['payment_method_type_id']) ? $model['payment_method_type_id'] : null,
                    'public_key'                => isset($model['public_key']) ? $model['public_key'] : null,
                    'access_token'              => isset($model['access_token']) ? $model['access_token'] : null,
                    'user_id'                   => $user->id,
                    'surchage'                  => isset($model['surchage']) ? $model['surchage'] : null,
                    'discount'                  => isset($model['discount']) ? $model['discount'] : null,
                ]);
                if (isset($model['installments'])) {
                    foreach ($model['installments'] as $installment) {
                        $installment['payment_method_id'] = $payment_method->id;
                        PaymentMethodInstallment::create($installment);
                    }
                }
            }
        }
    }
}
