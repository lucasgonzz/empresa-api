<?php

namespace Database\Seeders;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\Seeders\ArticleSeederHelper;
use App\Models\Article;
use App\Models\Category;
use App\Models\Description;
use Illuminate\Database\Seeder;

class ArticleForrajeriaSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        
        $articles = $this->get_articles();

        $helper = new ArticleSeederHelper();

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

            $helper->set_images($created_article, $article, 'forrajeria', 'webp');

            $helper->set_provider($created_article, $article);

            $this->createDescriptions($created_article); 

            ArticleHelper::setFinalPrice($created_article, config('app.USER_ID'));

            $helper->set_stock_movement($created_article, $article);
        }
    }

    function createDescriptions($created_article) {
            Description::create([
                'title'      => 'Purina Pro Plan® Perro Adulto Raza Mediana - Pollo & Arroz (15kg)',
                'content'    => 'Purina Pro Plan® Adulto Raza Mediana con OptiHealth es un alimento premium desarrollado científicamente para cubrir las necesidades nutricionales de perros adultos. Formulado con carne de pollo como primer ingrediente y una mezcla exclusiva de nutrientes esenciales que fortalecen el sistema inmunológico y promueven una digestión saludable.

                    Ingredientes principales:

                    Carne de pollo deshidratada

                    Arroz

                    Harina de trigo

                    Grasa animal (preservada con antioxidantes naturales)

                    Pulpa de remolacha

                    Aceite de pescado (fuente natural de omega 3)

                    Minerales: calcio, fósforo, zinc

                    Vitaminas: A, D3, E, C, complejo B

                    Prebióticos naturales y fibras vegetales

                    Antioxidantes naturales

                    Beneficios destacados:

                    Refuerza defensas naturales con vitamina C y E

                    Mejora la digestión gracias a los prebióticos

                    Promueve un pelaje sano y brillante

                    Alta palatabilidad con croquetas de tamaño óptimo',
                'article_id' => $created_article->id,
            ]);
    }

    function get_articles() {

        $categories = Category::all();
        
        $articles = [];

        for ($i = 1; $i <= 100; $i++) {
            $category = $categories[($i - 1) % count($categories)];

            $name = "{$category->name} Modelo " . $i;
            $bar_code = str_pad((string)rand(1000000000000, 9999999999999), 13, '0', STR_PAD_LEFT);
            $provider_code = str_pad((string)rand(10000000, 99999999), 8, STR_PAD_LEFT);
            $cost = rand(500, 5000);
            $percentage_gain = rand(10, 50);

            $articles[] = [
                'name'           => $name,
                'bar_code'       => $bar_code,
                'provider_code'  => $provider_code,
                'provider_id'    => rand(1,3),
                'stock'          => rand(10, 100),
                'cost'           => $cost,
                'category_id'    => $category->id,
                'percentage_gain'=> $percentage_gain,
                'category'       => $category,
            ];
        }
        return $articles;
    }
}
