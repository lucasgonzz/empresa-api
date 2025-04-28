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
        $models = [
            ['name' => 'Armado', 'position' => 1, 'user_id' => env('USER_ID')], 
            ['name' => 'Pintura', 'position' => 2, 'user_id' => env('USER_ID')], 
            ['name' => 'Terminado', 'position' => 3, 'user_id' => env('USER_ID')], 
        ];
        foreach ($models as $model) {
            OrderProductionStatus::create($model);
        }
    }
}
