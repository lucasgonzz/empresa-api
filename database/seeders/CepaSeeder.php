<?php

namespace Database\Seeders;

use App\Models\Cepa;
use Illuminate\Database\Seeder;

class CepaSeeder extends Seeder
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
                'name'  => 'Malbec',
            ],
            [
                'name'  => 'Cabernet',
            ],
            [
                'name'  => 'Grand Brut',
            ],
            [
                'name'  => 'Extra Brut',
            ],
        ];

        foreach ($models as $model) {
            
            $model['user_id'] = env('USER_ID');

            Cepa::create($model);
        }
    }
}
