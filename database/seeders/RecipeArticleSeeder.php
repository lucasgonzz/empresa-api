<?php

namespace Database\Seeders;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Models\Article;
use Illuminate\Database\Seeder;

class RecipeArticleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $articles = [
            
            // Este articulo voy a construir
            [
                'bar_code'          => '1000',
                'provider_code'     => 'p-1000',
                'name'              => 'Gabinete',
                'stock'             => 0,
                'cost'              => null,
                'provider_id'       => 2,
            ],


            // 
            [
                'bar_code'          => '100',
                'provider_code'     => 'p-100',
                'name'              => 'Pintura negra',
                'stock'             => 100,
                'cost'              => 1000,
                'costo_mano_de_obra'    => 100,
                'provider_id'       => 2,
            ],
            [
                'bar_code'          => '101',
                'provider_code'     => 'p-101',
                'name'              => 'Tornillo N° 8',
                'costo_mano_de_obra'    => 50,
                'stock'             => 100,
                'cost'              => 10,
                'provider_id'       => 2,
            ],
            [
                'bar_code'          => '102',
                'provider_code'     => 'p-102',
                'name'              => 'Enchufe embra',
                'costo_mano_de_obra'    => 10,
                'stock'             => 100,
                'cost'              => 100,
                'provider_id'       => 2,
            ],
        ];

        foreach ($articles as $article) {
            $article['user_id'] = env('USER_ID');
            $art = Article::create($article);
            ArticleHelper::setFinalPrice($art, env('USER_ID'));
        }
    }
}
