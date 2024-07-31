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
        $combustibles = $this->get_cobustibles();

        $impuestos = $this->get_impuestos();

        $sueldos = $this->get_sueldos();

        $user = User::where('company_name', 'Autopartes Boxes')->first();

        $num = 1;
        foreach ($combustibles as $model) {
            $model['num'] = $num;
            $model['user_id'] = $user->id;
            Expense::create($model);
            $num++;
        }
        
        $num = 1;
        foreach ($impuestos as $model) {
            $model['num'] = $num;
            $model['user_id'] = $user->id;
            Expense::create($model);
            $num++;
        }
        
        $num = 1;
        foreach ($sueldos as $model) {
            $model['num'] = $num;
            $model['user_id'] = $user->id;
            Expense::create($model);
            $num++;
        }
    }

    function get_cobustibles() {
        return [
            [
                'expense_concept_id'  => 1,
                'amount'              => 1000,
                'current_acount_payment_method_id'  => 1,
                'created_at'            => Carbon::now()->subMonths(4)
            ],
            [
                'expense_concept_id'  => 1,
                'amount'              => 2000,
                'current_acount_payment_method_id'  => 1,
                'created_at'            => Carbon::now()->subMonths(3)
            ],
            [
                'expense_concept_id'  => 1,
                'amount'              => 3000,
                'current_acount_payment_method_id'  => 1,
                'created_at'            => Carbon::now()->subMonths(2)
            ],
            [
                'expense_concept_id'  => 1,
                'amount'              => 4000,
                'current_acount_payment_method_id'  => 1,
                'created_at'            => Carbon::now()->subMonths(1)
            ],
            [
                'expense_concept_id'  => 1,
                'amount'              => 5000,
                'current_acount_payment_method_id'  => 1,
                'created_at'            => Carbon::now()->subMonths(0)
            ],
        ];
    }

    function get_impuestos() {
        return [
            [
                'expense_concept_id'  => 2,
                'amount'              => 5000,
                'current_acount_payment_method_id'  => 2,
                'created_at'            => Carbon::now()->subMonths(4)
            ],
            [
                'expense_concept_id'  => 2,
                'amount'              => 4000,
                'current_acount_payment_method_id'  => 2,
                'created_at'            => Carbon::now()->subMonths(3)
            ],
            [
                'expense_concept_id'  => 2,
                'amount'              => 3000,
                'current_acount_payment_method_id'  => 2,
                'created_at'            => Carbon::now()->subMonths(2)
            ],
            [
                'expense_concept_id'  => 2,
                'amount'              => 2000,
                'current_acount_payment_method_id'  => 2,
                'created_at'            => Carbon::now()->subMonths(1)
            ],
            [
                'expense_concept_id'  => 2,
                'amount'              => 1000,
                'current_acount_payment_method_id'  => 2,
                'created_at'            => Carbon::now()->subMonths(0)
            ],
        ];
    }

    function get_sueldos() {
        return [
            [
                'expense_concept_id'  => 3,
                'amount'              => 2500,
                'current_acount_payment_method_id'  => 3,
                'created_at'            => Carbon::now()->subMonths(4)
            ],
            [
                'expense_concept_id'  => 3,
                'amount'              => 2500,
                'current_acount_payment_method_id'  => 3,
                'created_at'            => Carbon::now()->subMonths(3)
            ],
            [
                'expense_concept_id'  => 3,
                'amount'              => 2500,
                'current_acount_payment_method_id'  => 3,
                'created_at'            => Carbon::now()->subMonths(2)
            ],
            [
                'expense_concept_id'  => 3,
                'amount'              => 2500,
                'current_acount_payment_method_id'  => 3,
                'created_at'            => Carbon::now()->subMonths(1)
            ],
            [
                'expense_concept_id'  => 3,
                'amount'              => 2500,
                'current_acount_payment_method_id'  => 3,
                'created_at'            => Carbon::now()->subMonths(0)
            ],
        ];
    }
}
