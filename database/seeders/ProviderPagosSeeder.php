<?php

namespace Database\Seeders;

use App\Http\Controllers\Helpers\CurrentAcountHelper;
use App\Http\Controllers\Helpers\CurrentAcountPagoHelper;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\Provider;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class ProviderPagosSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()

    {
        $num = 1;

        $user_id = env('USER_ID');

        $provider = Provider::where('name', 'Buenos Aires')->first();

        $credit_account = CreditAccount::where('model_name', 'provider')
                                        ->where('model_id', $provider->id)
                                        ->where('moneda_id', 1)
                                        ->first();

        for ($mes=12; $mes >= 0 ; $mes--) {

            $total = 100 + (12 - $mes) * 100;

            $pago = CurrentAcount::create([
                'haber'                             => $total,
                'detalle'                           => 'Pago N°'.$num,
                'description'                       => null,
                'status'                            => 'pago_from_client',
                'user_id'                           => $user_id,
                'num_receipt'                       => $num,
                'provider_id'                       => 1,
                'created_at'                        => Carbon::now()->subMonths($mes),
                'employee_id'                       => $user_id,
                'credit_account_id'                 => $credit_account->id,
            ]);

            $num++;

            CurrentAcountPagoHelper::attachPaymentMethods($pago, [
                [
                    'amount'    => $total / 2,
                    'current_acount_payment_method_id'  => 2,
                    'bank'                          => null,
                    'fecha_emision'                  => null,
                    'fecha_pago'                  => null,
                    'cobrado_at'                  => null,
                    'num'                           => null,
                    'credit_card_id'                => null,
                    'credit_card_payment_plan_id'   => null,
                ],
                [
                    'amount'    => $total / 2,
                    'current_acount_payment_method_id'  => 3,
                    'bank'                          => null,
                    'fecha_emision'                  => null,
                    'fecha_pago'                  => null,
                    'cobrado_at'                  => null,
                    'num'                           => null,
                    'credit_card_id'                => null,
                    'credit_card_payment_plan_id'   => null,
                ],
            ]);

            $pago->saldo = CurrentAcountHelper::getSaldo($credit_account->id, $pago) - $pago->haber;
            $pago->save();
            $pago_helper = new CurrentAcountPagoHelper($credit_account->id, 'provider', $provider->id, $pago);
            $pago_helper->init();
        } 
    }
}
