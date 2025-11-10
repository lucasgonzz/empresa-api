<?php

namespace Database\Seeders;

use App\Models\TurnoCaja;
use Illuminate\Database\Seeder;

class TurnoCajaSeeder extends Seeder
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
                'name'  => 'Mañana',
                'hora_inicio'   => '08:00:00',
                'hora_fin'   => '12:00:00',
            ],
            [
                'name'  => 'Tarde',
                'hora_inicio'   => '12:00:00',
                'hora_fin'   => '20:00:00',
            ],
        ];

        foreach ($models as $model) {
            
            $model['user_id'] = env('USER_ID');

            TurnoCaja::create($model);
        }
    }
}
