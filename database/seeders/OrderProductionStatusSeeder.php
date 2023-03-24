<?php

namespace Database\Seeders;

use App\Models\OrderProductionStatus;
use App\Models\User;
use Illuminate\Database\Seeder;

class OrderProductionStatusSeeder extends Seeder
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
            ['name' => 'Deposito', 'position' => 1, 'user_id' => $user->id], 
            ['name' => 'Producción', 'position' => 2, 'user_id' => $user->id], 
            ['name' => 'Entrega', 'position' => 3, 'user_id' => $user->id], 
            ['name' => 'Colocación', 'position' => 4, 'user_id' => $user->id],
            ['name' => 'Pintura', 'position' => 5, 'user_id' => $user->id],
        ];
        foreach ($models as $model) {
            OrderProductionStatus::create($model);
        }
    }
}
