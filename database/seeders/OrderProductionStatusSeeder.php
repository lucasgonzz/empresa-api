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
            ['name' => 'Chapa'], 
            ['name' => 'Corte'], 
            ['name' => 'Plegado'], 
            ['name' => 'Arenado'], 
            ['name' => 'Pintura en polvo'], 
            ['name' => 'Armado'], 
            ['name' => 'Terminado'], 
        ];

        $position = 0;
        foreach ($models as $model) {
            $position++;
            $model['position'] = $position;
            $model['user_id'] = config('app.USER_ID');
            OrderProductionStatus::create($model);
        }
    }
}
