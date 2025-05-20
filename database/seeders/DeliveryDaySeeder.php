<?php

namespace Database\Seeders;

use App\Models\DeliveryDay;
use Illuminate\Database\Seeder;

class DeliveryDaySeeder extends Seeder
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
                'day_of_week'  => 2,
            ],
            [
                'day_of_week'  => 4,
            ],
            [
                'day_of_week'  => 5,
            ],
        ];

        foreach ($models as $model) {
            
            $model['user_id'] = env('USER_ID');

            DeliveryDay::create($model);
        }
    }
}
