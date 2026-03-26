<?php

namespace Database\Seeders;

use App\Models\MODEL_NAME;
use Illuminate\Database\Seeder;

class MODEL_NAMESeeder extends Seeder
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
                'name'          => '',
                'otro_dato'     => '',
            ],
            [
                'name'          => '',
                'otro_dato'     => '',
            ],
        ];

        foreach ($models as $model) {
            
            $model['user_id'] = config('app.USER_ID');

            MODEL_NAME::create($model);
        }
    }
}
