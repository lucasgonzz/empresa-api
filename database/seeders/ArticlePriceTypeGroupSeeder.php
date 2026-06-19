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
                'user_id'   => config('app.USER_ID'),
                'articles'  => [
                    [   
                        'name'  => 'TIMBRE INALAMBRICO REDONDO CANDELA',
                    ],
                    [   
                        'name'  => 'CINTA MULTIPROPOSITO BLANCO 48MM X 9Mts. TACSA DUCTAC',
                    ],
                ],
            ]
        ];

        foreach ($models as $model) {
            
            $group = ArticlePriceTypeGroup::create([
                'user_id'   => $model['user_id'],
            ]);

            foreach ($model['articles'] as $article) {
                $article = Article::where('name', $article['name'])
                                    ->first();

                if ($article) {

                    $group->articles()->attach($article->id);
                }

            }
        }

    }
}
