<?php

namespace App\Http\Controllers\Helpers\category;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\caja\MovimientoCajaHelper;
use App\Models\Article;
use App\Models\MovimientoCaja;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class PriceTypeHelper {

	static function update_article_prices($category, $sub_category = null) {
        
        $articles = [];

        if (!is_null($category)) {

            $articles = Article::where('category_id', $category->id)
                                ->get();

        } else if (!is_null($sub_category)) {

            $articles = Article::where('sub_category_id', $sub_category->id)
                                ->get();
        }

        foreach ($articles as $article) {
            
            ArticleHelper::setFinalPrice($article);
        }
    }
}