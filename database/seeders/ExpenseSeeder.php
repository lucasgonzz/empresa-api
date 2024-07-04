<?php

namespace Database\Seeders;

use App\Models\Expense;
use App\Models\User;
use Illuminate\Database\Seeder;

class ExpenseSeeder extends Seeder
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
                'expense_concept_id'  => 1,
                'amount'              => rand(1000, 5000),
                'current_acount_payment_method_id'  => 1,
            ],
            [
                'expense_concept_id'  => 1,
                'amount'              => rand(1000, 5000),
                'current_acount_payment_method_id'  => 2,
            ],
            [
                'expense_concept_id'  => 2,
                'amount'              => rand(1000, 5000),
                'current_acount_payment_method_id'  => 3,
            ],
            [
                'amount'              => rand(1000, 5000),
                'expense_concept_id'  => 3,
                'current_acount_payment_method_id'  => 3,
            ],
        ];

        $user = User::where('company_name', 'Autopartes Boxes')->first();

        $num = 1;
        foreach ($models as $model) {
            $model['num'] = $num;
            $model['user_id'] = $user->id;
            Expense::create($model);
            $num++;
        }
    }
}
