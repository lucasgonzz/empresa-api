<?php

namespace Database\Seeders;

use App\Models\MODEL_NAME;
use Illuminate\Database\Seeder;

class MODEL_NAME Seeder extends Seeder
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
                'name'  => '',
            ],
            [
                'name'  => '',
            ],
            [
                'name'  => '',
            ],
            [
                'name'  => '',
            ],
        ];

        foreach ($models as $model) {
            
            $model['user_id'] = config('app.USER_ID');

            MODEL_NAME::create($model);
        }
    }
}
