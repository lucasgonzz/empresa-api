<?php

namespace Database\Seeders;

use App\Models\Article;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Database\Seeder;

class RecipeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $article = Article::where('user_id', env('USER_ID'))
                            ->where('name', 'Gabinete')
                            ->first();

        $recipe = Recipe::create([
            'num'           => 1,
            'article_id'    => $article->id,
            'address_id'    => 1,
            'user_id'       => env('USER_ID'),
        ]);
        
        $articles = [
            [
                'name'                          => 'Pintura negra',
                'order_production_status_id'    => 5,
                'address_id'                    => 1,
                'amount'                        => 0.5,
            ],
            [
                'name'                          => 'Tornillo N° 8',
                'order_production_status_id'    => 6,
                'address_id'                    => 1,
                'amount'                        => 10,
            ],
            [
                'name'                          => 'Enchufe embra',
                'order_production_status_id'    => 6,
                'address_id'                    => 1,
                'amount'                        => 5,
            ],
        ];
        foreach ($articles as $article) {
            $art = Article::where('user_id', env('USER_ID'))
                            ->where('name', $article['name'])
                            ->first();
            $recipe->articles()->attach($art->id, [
                                    'order_production_status_id'    => $article['order_production_status_id'],
                                    'address_id'                    => $article['address_id'],
                                    'amount'                        => $article['amount'],
                                ]);
        }
    }
}
