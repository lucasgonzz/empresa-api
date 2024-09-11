<?php

namespace Database\Seeders;

use App\Models\Article;
use App\Models\ArticleProperty;
use App\Models\ArticlePropertyValue;
use App\Models\ArticleVariant;
use Illuminate\Database\Seeder;

class ArticleVariantSeeder extends Seeder
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
                'variant_description'       => 'Amarillo XL',
                'article_property_values'   => [
                    'Amarillo',
                    'XL',
                ],
            ],
            [
                'variant_description'       => 'Amarillo S',
                'article_property_values'   => [
                    'Amarillo',
                    'S',
                ],
            ],
            [
                'variant_description'       => 'Rojo XL',
                'article_property_values'   => [
                    'Rojo',
                    'XL',
                ],
            ],
            [
                'variant_description'       => 'Rojo S',
                'article_property_values'   => [
                    'Rojo',
                    'S',
                ],
            ],
        ];

        $article_id = 1;

        foreach ($models as $model) {

            $article_variant = ArticleVariant::create([
                'article_id'                => $article_id,
                'variant_description'       => $model['variant_description'],
            ]);

            // ArticleProperty::create([
            //     'article_id'    => $article_id,
            //     'article_property_type_id'  => 
            // ]);

            foreach ($model['article_property_values'] as $_article_property_value) {
                    
                $article_property_value = ArticlePropertyValue::where('name', $_article_property_value)
                                                                ->first(); 

                $article_variant->article_property_values()->attach($article_property_value->id);
            }
        }
    }
}
