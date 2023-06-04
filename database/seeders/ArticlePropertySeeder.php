<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\ArticleProperty;

class ArticlePropertySeeder extends Seeder
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
            ArticleProperty::create($model);
        }
    }
}
