<?php

namespace Database\Seeders;

use App\Models\Article;
use App\Models\ArticlePriceTypeGroup;
use Illuminate\Database\Seeder;

class ArticlePriceTypeGroupSeeder extends Seeder
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
                'user_id'   => env('USER_ID'),
                'articles'  => [
                    [   
                        'name'  => 'Fanta',
                    ],
                    [   
                        'name'  => 'Lima limon',
                    ],
                ],
            ]
        ];

        foreach ($models as $model) {
            
            $group = ArticlePriceTypeGroup::create([
                'user_id'   => $model['user_id'],
            ]);

            foreach ($model['articles'] as $article) {
                $article = Article::where('user_id', $model['user_id'])
                                    ->where('name', $article['name'])
                                    ->first();

                $group->articles()->attach($article->id);
            }
        }
    }
}
