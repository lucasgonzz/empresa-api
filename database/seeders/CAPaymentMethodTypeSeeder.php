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
            /*
             * Retencion (mision compras-factura-manual-alicuotas, 17/9/2026).
             *
             * Es el tipo que hace que una retencion sufrida se pueda cargar como un medio de pago
             * mas del cobro: cancela deuda por su importe pero NO entra a la caja, igual que el
             * cheque. Si el cliente te debe $100.000 y te retiene $2.000, te paga $98.000 y la
             * deuda se cancela por $100.000; si el cobro registrara $98.000 le quedarian $2.000 de
             * deuda que ya pago.
             *
             * El slug es el que miran CurrentAcountController::pago() (para guardar el certificado
             * en `retenciones_sufridas`) y la SPA (para pedir los campos del certificado).
             *
             * 🔴 VA AL FINAL Y NO EN EL MEDIO: los ids de este catalogo estan hardcodeados en
             * varios lados (ver OpcionesDeCargaIaHelper, que da por sentado que el metodo de pago 1
             * es el Cheque). Insertar una fila antes correria todo lo de abajo.
             */
            [
                'name'  => 'Retencion',
                'slug'  => 'retencion',
            ],
        ];

        foreach ($models as $model) {
            
            CAPaymentMethodType::create($model);
        }
    }
}
