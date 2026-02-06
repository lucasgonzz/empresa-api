<?php

namespace Database\Seeders;

use App\Models\ExpenseCategory;
use Illuminate\Database\Seeder;

class ExpenseCategorySeeder extends Seeder
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
                'name'  => 'Impuestos',
            ],
            [
                'name'  => 'Gastos bancarios',
            ],
        ];

        foreach ($models as $model) {
            $model['user_id'] = config('app.USER_ID');
            ExpenseCategory::create($model);
        }
    }
}
