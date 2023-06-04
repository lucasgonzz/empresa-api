<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\ArticlePropertyType;

class ArticlePropertyTypeSeeder extends Seeder
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
                'num'   => 1,
                'name'  => 'Color',
            ],
            [
                'num'   => 2,
                'name'  => 'Talle',
            ],
        ];
        foreach ($models as $model) {
            ArticlePropertyType::create($model);
        }
    }
}
