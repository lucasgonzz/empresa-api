<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\model_name;

class model_nameSeeder extends Seeder
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
        ];
        foreach ($models as $model) {
            model_name::create($model);
        }
    }
}
