<?php

namespace Database\Seeders;

use App\Models\RecipeRouteType;
use Illuminate\Database\Seeder;

class RecipeRouteTypeSeeder extends Seeder
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
                'name'  => 'Interno',
            ],
            [
                'name'  => 'Externo',
            ],
        ];

        foreach ($models as $model) {
            
            $model['user_id'] = config('app.USER_ID');

            RecipeRouteType::create($model);
        }
    }
}
