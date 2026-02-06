<?php

namespace Database\Seeders;

use App\Models\ExpenseConcept;
use App\Models\User;
use Illuminate\Database\Seeder;

class ExpenseConceptSeeder extends Seeder
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
                'name'  => 'Combustible',
                'expense_category_id'   => null,
            ],
            [
                'name'  => 'Impuestos provinciales',
                'expense_category_id'   => 1,
            ],
            [
                'name'  => 'Impuestos Nacionales',
                'expense_category_id'   => 1,
            ],
            [
                'name'  => 'Sueldos',
                'expense_category_id'   => null,
            ],
            [
                'name'  => 'Mantenimiento de cuenta',
                'expense_category_id'   => 2,
            ],
            [
                'name'  => 'Comisiones bancarias',
                'expense_category_id'   => 2,
            ],
        ];

        $num = 1;
        foreach ($models as $model) {
            $model['user_id'] = config('app.USER_ID');
            $model['num'] = $num;
            ExpenseConcept::create($model);

            $num++;
        }
    }
}
