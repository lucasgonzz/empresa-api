<?php

namespace Database\Seeders;

use App\Models\Color;
use Illuminate\Database\Seeder;

class ColorSeeder extends Seeder
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
                'num'       => 1,
                'name'      => 'Rojo',
                'value'     => '#D83B3B',
                'user_id'   => 1,
            ],
            [
                'num'       => 2,
                'name'      => 'Verde',
                'value'     => '#45D83B',
                'user_id'   => 1,
            ],
        ];
        foreach ($models as $model) {
            Color::create($model);
        }
    }
}
