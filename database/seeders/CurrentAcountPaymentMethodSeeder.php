<?php

namespace Database\Seeders;

use App\Models\CAPaymentMethodType;
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
        $payment_methods = [
            [
                'name'  => 'Cheque',
                'type'  => 'cheque',
            ],


            [
                'name'  => 'Debito',
                'type'  => null,
            ],


            [
                'name'  => 'Efectivo',
                'type'  => null,
            ],


            [
                'name'  => 'Transferencia',
                'type'  => null,
            ],


            [
                'name'  => 'Credito',
                'type'  => 'tarjeta_de_credito',
            ],


            [
                'name'  => 'Mercado Pago',
                'type'  => null,
            ],


        ];
        foreach ($payment_methods as $payment_method) {

            $type_id = null;

            if ($payment_method['type']) {
                $type_id = CAPaymentMethodType::where('slug', $payment_method['type'])->first()->id;
            }
            CurrentAcountPaymentMethod::create([
                'name'                          => $payment_method['name'],
                'c_a_payment_method_type_id'    => $type_id,
            ]);
        }
    }
}
