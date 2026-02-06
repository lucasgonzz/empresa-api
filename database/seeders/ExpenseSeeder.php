<?php

namespace Database\Seeders;

use App\Models\Expense;
use App\Models\User;
use Illuminate\Database\Seeder;
use Carbon\Carbon;

class ExpenseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {


        $num = 1;
        for ($mes=12; $mes >= 0 ; $mes--) { 

            foreach ($this->expenses() as $model) {

                if ($mes > 0) {
                    $amount = $mes * $model['amount'] * 2;
                } else {
                    $amount = $model['amount'] / 2 * 2;
                }

                $model['num'] = $num;
                $model['amount'] = $amount;
                $model['user_id'] = config('app.USER_ID');
                $model['created_at'] = Carbon::now()->subMonths($mes);

                Expense::create($model);
                $num++;
            }
        }
    }

    function expenses() {
        return [
            [
                'expense_concept_id'  => 1,
                'amount'              => 50,
                'current_acount_payment_method_id'  => 1,
            ],
            [
                'expense_concept_id'  => 2,
                'amount'              => 70,
                'current_acount_payment_method_id'  => 2,
            ],
            [
                'expense_concept_id'  => 3,
                'amount'              => 90,
                'current_acount_payment_method_id'  => 3,
            ],
            [
                'expense_concept_id'  => 4,
                'amount'              => 120,
                'current_acount_payment_method_id'  => 4,
            ],
        ];
    }

    function get_cobustibles() {
        return [
            [
                'expense_concept_id'  => 1,
                'amount'              => 500,
                'current_acount_payment_method_id'  => 1,
                'created_at'            => Carbon::now()->subMonths(4)
            ],
            [
                'expense_concept_id'  => 1,
                'amount'              => 1000,
                'current_acount_payment_method_id'  => 1,
                'created_at'            => Carbon::now()->subMonths(3)
            ],
            [
                'expense_concept_id'  => 1,
                'amount'              => 1500,
                'current_acount_payment_method_id'  => 1,
                'created_at'            => Carbon::now()->subMonths(2)
            ],
            [
                'expense_concept_id'  => 1,
                'amount'              => 2000,
                'current_acount_payment_method_id'  => 1,
                'created_at'            => Carbon::now()->subMonths(1)
            ],
            [
                'expense_concept_id'  => 1,
                'amount'              => 2500,
                'current_acount_payment_method_id'  => 1,
                'created_at'            => Carbon::now()->subMonths(0)
            ],
        ];
    }

    function get_impuestos() {
        return [
            [
                'expense_concept_id'  => 2,
                'amount'              => 4000,
                'current_acount_payment_method_id'  => 2,
                'created_at'            => Carbon::now()->subMonths(4)
            ],
            [
                'expense_concept_id'  => 2,
                'amount'              => 3000,
                'current_acount_payment_method_id'  => 2,
                'created_at'            => Carbon::now()->subMonths(3)
            ],
            [
                'expense_concept_id'  => 2,
                'amount'              => 2000,
                'current_acount_payment_method_id'  => 2,
                'created_at'            => Carbon::now()->subMonths(2)
            ],
            [
                'expense_concept_id'  => 2,
                'amount'              => 1000,
                'current_acount_payment_method_id'  => 2,
                'created_at'            => Carbon::now()->subMonths(1)
            ],
            [
                'expense_concept_id'  => 2,
                'amount'              => 500,
                'current_acount_payment_method_id'  => 2,
                'created_at'            => Carbon::now()->subMonths(0)
            ],
        ];
    }

    function get_sueldos() {
        return [
            [
                'expense_concept_id'  => 3,
                'amount'              => 1500,
                'current_acount_payment_method_id'  => 3,
                'created_at'            => Carbon::now()->subMonths(4)
            ],
            [
                'expense_concept_id'  => 3,
                'amount'              => 1500,
                'current_acount_payment_method_id'  => 3,
                'created_at'            => Carbon::now()->subMonths(3)
            ],
            [
                'expense_concept_id'  => 3,
                'amount'              => 1500,
                'current_acount_payment_method_id'  => 3,
                'created_at'            => Carbon::now()->subMonths(2)
            ],
            [
                'expense_concept_id'  => 3,
                'amount'              => 1500,
                'current_acount_payment_method_id'  => 3,
                'created_at'            => Carbon::now()->subMonths(1)
            ],
            [
                'expense_concept_id'  => 3,
                'amount'              => 1500,
                'current_acount_payment_method_id'  => 3,
                'created_at'            => Carbon::now()->subMonths(0)
            ],
        ];
    }
}
