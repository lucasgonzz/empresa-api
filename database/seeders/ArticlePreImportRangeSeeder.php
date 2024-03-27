<?php

namespace Database\Seeders;

use App\Models\ArticlePreImportRange;
use Illuminate\Database\Seeder;

class ArticlePreImportRangeSeeder extends Seeder
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
                'user_id'   => 500,
                'min'       => 0,
                'max'       => 10,
                'color'     => 'verde',
            ],
            [
                'user_id'   => 500,
                'min'       => 10,
                'max'       => 20,
                'color'     => 'amarillo',
            ],
            [
                'user_id'   => 500,
                'min'       => 20,
                'max'       => 100,
                'color'     => 'rojo',
            ],
        ];

        foreach ($models as $model) {
            ArticlePreImportRange::create($model);
        }
    }
}
