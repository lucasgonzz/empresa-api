<?php

namespace Database\Seeders;

use App\Models\Article;
use App\Models\ArticlePerformance;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class ArticlePerformanceSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $articles = Article::where('user_id', 500)
                            ->get();

        foreach ($articles as $article) {
            for ($meses_atras=12; $meses_atras > 0; $meses_atras--) { 
                $models[] = [
                    'article_id'    => $article->id,
                    'article_name'  => $article->name,
                    'cost'          => $article->cost,
                    'price'         => $article->final_price,
                    'amount'        => $meses_atras * $article->id,
                    'provider_id'   => $article->provider_id,
                    'category_id'   => $article->category_id,
                    'created_at'    => Carbon::today()->subMonths($meses_atras)->startOfMonth(),
                    'user_id'       => 1,
                ];
            }
        }

        foreach ($models as $model) {
            ArticlePerformance::create($model);
        }
    }
}
