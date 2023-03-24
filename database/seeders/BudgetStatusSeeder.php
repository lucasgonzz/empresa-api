<?php

namespace Database\Seeders;

use App\Models\BudgetStatus;
use Illuminate\Database\Seeder;

class BudgetStatusSeeder extends Seeder
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
                'name' => 'Sin confirmar',
            ],
            [
                'name' => 'Confirmado',
            ],
        ];
        foreach ($models as $model) {
            BudgetStatus::create([
                'name' => $model['name'],
            ]);
        }
    }
}
