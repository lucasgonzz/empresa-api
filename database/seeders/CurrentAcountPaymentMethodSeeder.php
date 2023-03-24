<?php

namespace Database\Seeders;

use App\Models\CurrentAcountPaymentMethod;
use Illuminate\Database\Seeder;

class CurrentAcountPaymentMethodSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $payment_methods = ['Cheque', 'Debito', 'Efectivo', 'Transferencia', 'Credito'];
        foreach ($payment_methods as $payment_method) {
            CurrentAcountPaymentMethod::create([
                'name' => $payment_method
            ]);
        }
    }
}
