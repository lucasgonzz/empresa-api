<?php

namespace Database\Seeders;

use App\Models\InputsSize;
use Illuminate\Database\Seeder;

class InputsSizeSeeder extends Seeder
{
    public function run()
    {
        $models = [
            [
                'name' => 'Normal',
                'slug' => 'normal',
            ],
            [
                'name' => 'Pequeño',
                'slug' => 'small',
            ],
        ];

        foreach ($models as $model) {
            InputsSize::create($model);
        }
    }
}
