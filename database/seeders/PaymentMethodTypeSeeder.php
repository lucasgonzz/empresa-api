<?php

namespace Database\Seeders;

use App\Models\PaymentMethodType;
use Illuminate\Database\Seeder;

class PaymentMethodTypeSeeder extends Seeder
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
                'name' => 'MercadoPago'
            ],
        ];
        foreach ($models as $model) {
            PaymentMethodType::create([
                'name' => $model['name'],
            ]);
        }
    }
}
