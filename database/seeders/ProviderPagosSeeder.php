<?php

namespace Database\Seeders;

use App\Http\Controllers\Helpers\CurrentAcountHelper;
use App\Http\Controllers\Helpers\CurrentAcountPagoHelper;
use App\Models\CurrentAcount;
use Illuminate\Database\Seeder;
use Carbon\Carbon;

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

        $user_id = 500;

        for ($mes=12; $mes >= 0 ; $mes--) {

            $total = 700 + (12 - $mes) * 100;

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
            ]);

            $num++;

            CurrentAcountPagoHelper::attachPaymentMethods($pago, [
                [
                    'amount'    => $total / 2,
                    'current_acount_payment_method_id'  => 2,
                    'bank'                          => null,
                    'payment_date'                  => null,
                    'num'                           => null,
                    'credit_card_id'                => null,
                    'credit_card_payment_plan_id'   => null,
                ],
                [
                    'amount'    => $total / 2,
                    'current_acount_payment_method_id'  => 3,
                    'bank'                          => null,
                    'payment_date'                  => null,
                    'num'                           => null,
                    'credit_card_id'                => null,
                    'credit_card_payment_plan_id'   => null,
                ],
            ]);

            $pago->saldo = CurrentAcountHelper::getSaldo('provider', 1, $pago) - $pago->haber;
            $pago->save();
            $pago_helper = new CurrentAcountPagoHelper('provider', 1, $pago);
            $pago_helper->init();
        } 
    }
}
