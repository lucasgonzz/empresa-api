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
            ],
            [
                'name'  => 'Impuestos',
            ],
            [
                'name'  => 'Sueldos',
            ],
            [
                'name'  => 'Gastos Bancarios',
            ],
        ];

        $num = 1;
        foreach ($models as $model) {
            $model['user_id'] = env('USER_ID');
            $model['num'] = $num;
            ExpenseConcept::create($model);

            $num++;
        }
    }
}
