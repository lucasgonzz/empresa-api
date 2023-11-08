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
                'name'                          => 'Pata de cama',
                'order_production_status_id'    => 1,
                'address_id'                    => 1,
                'amount'                        => 4,
            ],
            [
                'name'                          => 'Marco para cama',
                'order_production_status_id'    => 1,
                'address_id'                    => 1,
                'amount'                        => 1,
            ],
            [
                'name'                          => 'Clavos N° 2',
                'order_production_status_id'    => 1,
                'address_id'                    => 1,
                'amount'                        => 10,
            ],
            [
                'name'                          => 'Pintura para cama',
                'order_production_status_id'    => 2,
                'address_id'                    => 1,
                'amount'                        => 1,
            ],
        ];
        foreach ($articles as $article) {
            $art = Article::where('user_id', $user->id)
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
