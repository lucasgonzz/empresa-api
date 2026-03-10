<?php

namespace Database\Seeders;

use App\Models\CAPaymentMethodType;
use Illuminate\Database\Seeder;

class CAPaymentMethodTypeSeeder extends Seeder
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
                'name'  => 'Tarjeta de credito',
                'slug'  => 'tarjeta_de_credito',
            ],
            [
                'name'  => 'Cheque',
                'slug'  => 'cheque',
            ],
        ];

        foreach ($models as $model) {
            
            CAPaymentMethodType::create($model);
        }
    }
}
