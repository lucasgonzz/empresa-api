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
        $user = User::where('company_name', 'Autopartes Boxes')->first();
        $article = Article::where('user_id', $user->id)
                            ->where('name', 'Prensa Espirales Universal')
                            ->first();
        $recipe = Recipe::create([
            'num'           => 1,
            'article_id'    => $article->id,
            'address_id'    => 1,
            'user_id'       => $user->id,
        ]);
        $articles = [
            [
                'name'                          => 'Kit 3 Relojes Orlan Rober Classic Aceite Agua Voltímetro',
                'order_production_status_id'    => 1,
                'address_id'                    => 1,
                'amount'                        => 4,
            ],
            [
                'name'                          => 'Amortiguadores de Baul 1945NR@1945NR',
                'order_production_status_id'    => 1,
                'address_id'                    => 1,
                'amount'                        => 1,
            ],
            [
                'name'                          => 'Batería de auto UB620M',
                'order_production_status_id'    => 1,
                'address_id'                    => 1,
                'amount'                        => 10,
            ],
            [
                'name'                          => 'Bobina de Encendido VX21894',
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
