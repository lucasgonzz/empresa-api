<?php

namespace Database\Seeders;

use App\Models\DepositMovementStatus;
use Illuminate\Database\Seeder;

class DepositMovementStatusSeeder extends Seeder
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
                'name' => 'En proceso'
            ],
            [
                'name' => 'Recibido'
            ],
        ];
        foreach ($models as $model) {
            DepositMovementStatus::create([
                'name' => $model['name']
            ]);
        }
    }
}
