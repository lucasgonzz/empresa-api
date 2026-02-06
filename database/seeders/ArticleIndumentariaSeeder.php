<?php

namespace Database\Seeders;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\Seeders\ArticleSeederHelper;
use App\Models\Article;
use App\Models\ArticleProperty;
use App\Models\ArticlePropertyType;
use App\Models\ArticlePropertyValue;
use App\Models\Category;
use App\Models\Image;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

class ArticleIndumentariaSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $articles = $this->get_articles();

        foreach ($articles as $article) {
            
            $created_article = Article::create([
                'name'      => $article['name'],
                'bar_code'      => $article['bar_code'],
                'provider_code'      => $article['provider_code'],
                'provider_id'       => $article['provider_id'],
                'category_id'       => $article['category_id'],
                'cost'      => $article['cost'],
                'percentage_gain'      => $article['percentage_gain'],
                'user_id'      => config('app.USER_ID'),
            ]);

            ArticleSeederHelper::set_images($created_article, $article, 'indumentaria');

            ArticleSeederHelper::set_provider($created_article, $article);

            $this->set_article_variants($created_article, $article);

            // $this->createDescriptions($article, $article); 

            ArticleHelper::setFinalPrice($created_article, config('app.USER_ID'));

            // $this->setStockMovement($article, $article);
        }

    }

    function set_article_variants($created_article, $article) {

        $article['variants'] = [
            'article_properties'   => [
                [
                    'article_property_type' => 'Color',
                    'article_property_values'   => [
                        'Amarillo',
                        'Azul',
                    ],
                ],
                [
                    'article_property_type' => 'Talle',
                    'article_property_values'   => [
                        'XL',
                        'L',
                    ],
                ],
            ],
        ];

        foreach ($article['variants']['article_properties'] as $article_property) {
            $article_property_type = ArticlePropertyType::where('name', $article_property['article_property_type'])->first();

            $article_property_model = ArticleProperty::create([
                'article_id'    => $created_article->id,
                'article_property_type_id'  => $article_property_type->id,
            ]);

            foreach ($article_property['article_property_values'] as $article_property_value) {
                
                $article_property_value_model = ArticlePropertyValue::where('name', $article_property_value)->first();

                $article_property_model->article_property_values()->attach($article_property_value_model->id);
            }
        }
    }


    function get_articles() {

        $categories = Category::all();
        
        $articles = [];

        for ($i = 1; $i <= 100; $i++) {
            $category = $categories[($i - 1) % count($categories)];

            $name = "{$category->name} Modelo " . $i;
            // $name = "{$category} Modelo " . str_pad($i, 3, '0', STR_PAD_LEFT);
            // Genero códigos: bar_code de 13 dígitos, provider_code 8 dígitos
            $bar_code = str_pad((string)rand(1000000000000, 9999999999999), 13, '0', STR_PAD_LEFT);
            $provider_code = str_pad((string)rand(10000000, 99999999), 8, STR_PAD_LEFT);
            // Costo entre 500 y 5000 (ej US$ o AR$)
            $cost = rand(500, 5000);
            // Margen 10‑50%
            $percentage_gain = rand(10, 50);

            $articles[] = [
                'name'           => $name,
                'bar_code'        => $bar_code,
                'provider_code'  => $provider_code,
                'provider_id'           => rand(1,3),
                'stock'           => rand(10, 100),
                'cost'           => $cost,
                'category_id'           => $category->id,
                'percentage_gain'         =>$percentage_gain,
                'category'       => $category,
            ];
        }
        return $articles;
    }
}
