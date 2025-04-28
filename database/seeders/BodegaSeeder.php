<?php

namespace Database\Seeders;

use App\Models\Bodega;
use Illuminate\Database\Seeder;

class BodegaSeeder extends Seeder
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
                'name'  => 'Trumpeter',
            ],
            [
                'name'  => 'Elemento',
            ],
            [
                'name'  => 'Cafayate',
            ],
            [
                'name'  => 'Grand Brut',
            ],
            [
                'name'  => 'Perrier-Joüet',
            ],
            [
                'name'  => 'Rutini',
            ],
        ];

        foreach ($models as $model) {
            
            $model['user_id'] = env('USER_ID');

            Bodega::create($model);
        }
    }
}
