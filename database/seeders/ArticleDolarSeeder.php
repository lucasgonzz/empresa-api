<?php

namespace Database\Seeders;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\Seeders\ArticleSeederHelper;
use Illuminate\Database\Seeder;

class ArticleDolarSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        
        $articles = [
            [
                'num'               => 100,
                'bar_code'          => 1111,
                'name'              => 'Dolar',
                'cost'              => 1,
                'cost_in_dollars'   => 1,
                'percentage_gain'   => 100,
                'iva_id'            => 2,
            ],
        ];  

        $helper = new ArticleSeederHelper();

        foreach ($articles as $article) {

            $art = $helper->crear_article($article);

            ArticleHelper::setFinalPrice($art, config('app.USER_ID'));

            ArticleSeederHelper::set_stock_movement($art, $article);
            // ArticleHelper::setArticleStockFromAddresses($art);
        }
    }
}
