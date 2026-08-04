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
        // Access token de una cuenta de prueba de Mercado Pago, para el medio de pago de
        // ejemplo. Viene de config/services.php (.env del servidor), nunca hardcodeado
        // (grupo 220, prompt 02, repositorio publico).
        $mercado_pago_token = config('services.mercado_pago.token');

        if (empty($mercado_pago_token) && $this->command) {
            // No frena el seeder: solo avisa que este dato quedo sin configurar.
            $this->command->warn('PaymentMethodSeeder: MERCADO_PAGO_SEED_ACCESS_TOKEN no esta configurado, se sembro access_token=null.');
        }

        $models = [
            [
                'name' => 'MercadoPago',
                'description' => 'Paga Online con tu cuenta de MercadoPago',
                'payment_method_type_id' => 1,
                'public_key' => 'TEST-4f17cb64-8711-487f-b5f3-2e363c42c717',
                'access_token' => empty($mercado_pago_token) ? null : $mercado_pago_token,
                'surchage'  => 100,
            ],
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
        
        foreach ($models as $model) {
            $payment_method = PaymentMethod::create([
                'name'                      => isset($model['name']) ? $model['name'] : null,
                'description'               => isset($model['description']) ? $model['description'] : null,
                'payment_method_type_id'    => isset($model['payment_method_type_id']) ? $model['payment_method_type_id'] : null,
                'public_key'                => isset($model['public_key']) ? $model['public_key'] : null,
                'access_token'              => isset($model['access_token']) ? $model['access_token'] : null,
                'user_id'                   => config('app.USER_ID'),
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
