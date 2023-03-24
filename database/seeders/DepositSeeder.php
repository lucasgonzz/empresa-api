<?php

namespace Database\Seeders;

use App\Models\Deposit;
use App\Models\User;
use Illuminate\Database\Seeder;

class DepositSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $user = User::where('company_name', 'lucas')->first();
        $models = [
            [
                'num'           => 1,
                'name'          => 'Nave',
                'description'   => 'Este es el mas importante',
                'user_id'   => $user->id,
            ],
            [
                'num'           => 2,
                'name'          => 'Fila',
                'description'   => '',
                'user_id'   => $user->id,
            ],
            [
                'num'           => 3,
                'name'          => 'Columna',
                'description'   => '',
                'user_id'   => $user->id,
            ],
        ];
        foreach ($models as $model) {
            Deposit::create($model);
        }
    }
}
