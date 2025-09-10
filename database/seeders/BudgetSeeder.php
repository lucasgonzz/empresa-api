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
                'observations'  => 'El envio se realiza una vez pagado el 50% del total',
                'user_id'       => env('USER_ID'),
            ],
        ];
        foreach ($models as $model) {

            $budget = Budget::create($model);

            $articles = Article::where('user_id', env('USER_ID'))
                            ->take(3)
                            ->get();

            $articles_ = [];


            $total = 0;

            foreach ($articles as $article) {
                
                $amount = rand(1,6);
                $price = $article->final_price;

                $_article = [];
                $_article['id']                 = $article->id;
                $_article['name']               = $article->name;
                $_article['bar_code']           = $article->bar_code;
                $_article['provider_code']      = $article->provider_code;
                $_article['status']             = $article->status;
                $_article['pivot']['amount']    = $amount;
                $_article['pivot']['price']     = $price;
                $_article['pivot']['bonus']     = 5;
                $_article['pivot']['location']  = null;
                $articles_[] = $_article;

                $total += $price * $amount; 
            }

            $budget->total = $total;
            $budget->save();
            
            BudgetHelper::attachArticles($budget, $articles_);
        }
    }
}
