<?php

namespace Database\Seeders;

use App\Models\Article;
use App\Models\ArticleProperty;
use App\Models\ArticlePropertyValue;
use Illuminate\Database\Seeder;

class ArticlePropertySeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $article_id = 1;
        $models = [
            [
                'article_id'                => $article_id,
                'article_property_type_id'  => 1,
                'article_property_values'   => [
                    'Amarillo',
                    'Rojo',
                ],
            ],
            [
                'article_id'                => $article_id,
                'article_property_type_id'  => 2,
                'article_property_values'   => [
                    'XL',
                    'S',
                ],
            ],
        ];
        foreach ($models as $model) {
            $article_property = ArticleProperty::create([
                'article_id'                    => $model['article_id'],
                'article_property_type_id'      => $model['article_property_type_id'],
            ]);
            foreach ($model['article_property_values'] as $_article_property_value) {
                $article_property_value = ArticlePropertyValue::where('article_property_type_id', $model['article_property_type_id'])
                                                                ->where('name', $_article_property_value)
                                                                ->first();
                $article_property->article_property_values()->attach($article_property_value->id);
            }
        }
    }
}
