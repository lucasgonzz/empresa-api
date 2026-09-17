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


            /*
             * Retencion (mision compras-factura-manual-alicuotas, 17/9/2026).
             *
             * El medio de pago con el que se carga una retencion sufrida al cobrarle a un cliente
             * que es agente de retencion. Suma al haber del cobro (o sea, cancela deuda) y no toca
             * caja, por su tipo `retencion` — ver CAPaymentMethodTypeSeeder.
             *
             * 🔴 VA ULTIMO. Los ids de este catalogo se usan hardcodeados en varios lados (el 1 es
             * el Cheque y el 3 el Efectivo, que es el default del formulario de cobro de la SPA):
             * meter una fila en el medio los correria a todos.
             */
            [
                'name'  => 'Retención',
                'type'  => 'retencion',
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
