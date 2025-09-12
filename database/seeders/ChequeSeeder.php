<?php

namespace Database\Seeders;

use App\Http\Controllers\Helpers\CurrentAcountHelper;
use App\Http\Controllers\Helpers\CurrentAcountPagoHelper;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class ChequeSeeder extends Seeder
{
        
    function run() {
        $this->clientes();
        $this->proveedores();
    }

    public function proveedores()
    {
        $models = [
            // Pendientes, todavia no se pueden cobrar
            [
                'banco' => 'Banco nacion',
                'fecha_emision' => Carbon::today()->subDays(10),
                'fecha_pago'    => Carbon::today()->addDays(10),
                'amount'        => 1000,
                'tipo'          => 'recibido',
                'provider_id'     => 1,
                'es_echeq'      => 0,
            ],
            [
                'banco' => 'Banco Entre Rios',
                'fecha_emision' => Carbon::today()->subDays(10),
                'fecha_pago'    => Carbon::today()->addDays(12),
                'amount'        => 1000,
                'tipo'          => 'recibido',
                'provider_id'     => 1,
                'es_echeq'      => 1,
            ],

            // Disponibles, ya se pueden cobrar
            [
                'banco' => 'Banco BSAS',
                'fecha_emision' => Carbon::today()->subDays(40),
                'fecha_pago'    => Carbon::today()->subDays(1),
                'amount'        => 20000,
                'tipo'          => 'recibido',
                'provider_id'     => 1,
                'es_echeq'      => 0,
            ],

            // Pronto a vencer, ya se pueden cobrar y vencen en menos de 3 dias
            [
                'banco' => 'Banco Santa Fe',
                'fecha_emision' => Carbon::today()->subDays(40),
                'fecha_pago'    => Carbon::today()->subDays(28),
                'amount'        => 5000,
                'tipo'          => 'recibido',
                'provider_id'     => 1,
                'es_echeq'      => 0,
            ],
        ];

        $index = 1;

        foreach ($models as $model) {

            $model['current_acount_payment_method_id'] = 1;
            $model['numero'] = rand(11111111, 99999999999);
            $model['amount'] = rand(1000, 10000);


            $credit_account = CreditAccount::where('model_name', 'provider')
                                            ->where('model_id', $model['provider_id'])
                                            ->where('moneda_id', 1)
                                            ->first();

            $pago = CurrentAcount::create([
                'haber'                             => $model['amount'],
                'description'                       => null,
                'status'                            => 'pago_from_client',
                'user_id'                           => env('USER_ID'),
                'num_receipt'                       => $index,
                'detalle'                           => 'Pago N°'.$index,
                'provider_id'                         => $model['provider_id'],
                'created_at'                        => Carbon::now(),
                'credit_account_id'                 => $credit_account->id,
            ]);

            $index++;

            CurrentAcountPagoHelper::attachPaymentMethods($pago, [$model]);

            $pago->saldo = CurrentAcountHelper::getSaldo($credit_account->id, $pago) - $pago->haber;
            $pago->save();

            $pago_helper = new CurrentAcountPagoHelper($credit_account->id, 'provider', $model['provider_id'], $pago);
            $pago_helper->init();
            
            CurrentAcountHelper::update_credit_account_saldo($credit_account->id);
        }

    }

    public function clientes()
    {
        $models = [
            // Pendientes, todavia no se pueden cobrar
            [
                'banco' => 'Banco nacion',
                'fecha_emision' => Carbon::today()->subDays(10),
                'fecha_pago'    => Carbon::today()->addDays(10),
                'amount'        => 1000,
                'tipo'          => 'recibido',
                'client_id'     => 1,
                'es_echeq'      => 0,
            ],
            [
                'banco' => 'Banco Entre Rios',
                'fecha_emision' => Carbon::today()->subDays(10),
                'fecha_pago'    => Carbon::today()->addDays(12),
                'amount'        => 1000,
                'tipo'          => 'recibido',
                'client_id'     => 1,
                'es_echeq'      => 1,
            ],

            // Disponibles, ya se pueden cobrar
            [
                'banco' => 'Banco BSAS',
                'fecha_emision' => Carbon::today()->subDays(40),
                'fecha_pago'    => Carbon::today()->subDays(1),
                'amount'        => 20000,
                'tipo'          => 'recibido',
                'client_id'     => 1,
                'es_echeq'      => 0,
            ],

            // Pronto a vencer, ya se pueden cobrar y vencen en menos de 3 dias
            [
                'banco' => 'Banco Santa Fe',
                'fecha_emision' => Carbon::today()->subDays(40),
                'fecha_pago'    => Carbon::today()->subDays(28),
                'amount'        => 5000,
                'tipo'          => 'recibido',
                'client_id'     => 1,
                'es_echeq'      => 0,
            ],
        ];

        $index = 1;

        foreach ($models as $model) {

            $model['current_acount_payment_method_id'] = 1;
            $model['numero'] = rand(11111111, 99999999999);
            $model['amount'] = rand(1000, 10000);


            $credit_account = CreditAccount::where('model_name', 'client')
                                            ->where('model_id', $model['client_id'])
                                            ->where('moneda_id', 1)
                                            ->first();

            $pago = CurrentAcount::create([
                'haber'                             => $model['amount'],
                'description'                       => null,
                'status'                            => 'pago_from_client',
                'user_id'                           => env('USER_ID'),
                'num_receipt'                       => $index,
                'detalle'                           => 'Pago N°'.$index,
                'client_id'                         => $model['client_id'],
                'created_at'                        => Carbon::now(),
                'credit_account_id'                 => $credit_account->id,
            ]);

            $index++;

            CurrentAcountPagoHelper::attachPaymentMethods($pago, [$model]);

            $pago->saldo = CurrentAcountHelper::getSaldo($credit_account->id, $pago) - $pago->haber;
            $pago->save();

            $pago_helper = new CurrentAcountPagoHelper($credit_account->id, 'client', $model['client_id'], $pago);
            $pago_helper->init();
            
            CurrentAcountHelper::update_credit_account_saldo($credit_account->id);
        }

    }
}
