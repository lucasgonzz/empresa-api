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
        $user = User::where('company_name', 'lucas')->first();
        $article = Article::where('user_id', $user->id)
                            ->where('name', 'Plaqueta de BSAS')
                            ->first();
        $recipe = Recipe::create([
            'num'           => 1,
            'article_id'    => $article->id,
            'user_id'       => $user->id
        ]);
        $articles = [
            [
                'name'                          => 'Tornillo num 6',
                'order_production_status_id'    => 1,
                'amount'                        => 1,
            ],
            [
                'name'                          => 'Boton chico blanco',
                'order_production_status_id'    => 2,
                'amount'                        => 2,
            ],
            [
                'name'                          => 'Cable 10cm',
                'order_production_status_id'    => 3,
                'amount'                        => 2,
            ],
            [
                'name'                          => 'Carcaza negra',
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
