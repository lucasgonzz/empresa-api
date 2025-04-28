<?php

namespace Database\Seeders;

use App\Http\Controllers\Helpers\BudgetHelper;
use App\Models\Article;
use App\Models\Budget;
use App\Models\User;
use Illuminate\Database\Seeder;

class BudgetSeeder extends Seeder
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
                'num'           => 1,
                'client_id'     => 1,
                'observations'  => 'Muchas cosas por hacer',
                'user_id'       => env('USER_ID'),
            ],
        ];
        foreach ($models as $model) {
            $budget = Budget::create($model);
            $articles = Article::where('user_id', env('USER_ID'))->get();
            $articles_ = [];
            foreach ($articles as $article) {
                $_article = [];
                $_article['id']                 = $article->id;
                $_article['name']                 = $article->name;
                $_article['bar_code']                 = $article->bar_code;
                $_article['provider_code']                 = $article->provider_code;
                $_article['status']             = $article->status;
                $_article['pivot']['amount']    = rand(1,6);
                $_article['pivot']['price']     = 123;
                $_article['pivot']['bonus']     = 5;
                $_article['pivot']['location']  = 'mesada';
                $articles_[] = $_article;
            }
            BudgetHelper::attachArticles($budget, $articles_);
        }
    }
}
