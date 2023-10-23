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
        $user = User::where('company_name', 'Jugueteria Rosario')->first();
        $article = Article::where('user_id', $user->id)
                            ->where('name', 'Cama dos plazas')
                            ->first();
        $recipe = Recipe::create([
            'num'           => 1,
            'article_id'    => $article->id,
            'user_id'       => $user->id
        ]);
        $articles = [
            [
                'name'                          => 'Martillo',
                'order_production_status_id'    => 1,
                'amount'                        => 1,
            ],
            [
                'name'                          => 'Martillo grande',
                'order_production_status_id'    => 2,
                'amount'                        => 2,
            ],
            [
                'name'                          => 'Pinza',
                'order_production_status_id'    => 3,
                'amount'                        => 2,
            ],
            [
                'name'                          => 'Alicate',
                'order_production_status_id'    => 4,
                'amount'                        => 1,
            ],
        ];
        foreach ($articles as $article) {
            $art = Article::where('user_id', $user->id)
                            ->where('name', $article['name'])
                            ->first();
            $recipe->articles()->attach($art->id, [
                                    'order_production_status_id'    => $article['order_production_status_id'],
                                    'amount'                        => $article['amount'],
                                ]);
        }
    }
}
