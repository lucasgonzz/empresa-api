<?php

namespace Database\Seeders;

use App\Models\Article;
use App\Models\Combo;
use Illuminate\Database\Seeder;

class ComboSeeder extends Seeder
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
                'name'          => 'Combo 1',
                'user_id'       => env('USER_ID'),
                'price'         => 1000,
                'articles'      => [
                    [
                        'name'      => 'Yerba',
                        'amount'    => 2
                    ],
                    [
                        'name'      => 'Mate torpedo',
                        'amount'    => 4
                    ],
                ],
            ]
        ];

        $num = 1;
        foreach ($models as $model) {
            
            $combo = Combo::create([
                'num'           => $num,
                'name'          => $model['name'],
                'price'         => $model['price'],
                'user_id'       => $model['user_id'],
            ]);

            $num++;

            foreach ($model['articles'] as $article) {

                $article_model = Article::where('name', $article['name'])->first();
                
                $combo->articles()->attach($article_model->id, [
                    'amount'    => $article['amount'],
                ]);
            }
        }
    }
}
