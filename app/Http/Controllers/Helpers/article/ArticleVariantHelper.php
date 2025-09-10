<?php

namespace App\Http\Controllers\Helpers\article;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\UserHelper;
use App\Models\ArticleProperty;
use App\Models\ArticlePropertyType;
use App\Models\PriceType;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class ArticleVariantHelper {

    static function set_default_properties($article) {
        
        if (UserHelper::hasExtencion('article_variants')) {

            $article_property_types = ArticlePropertyType::all();

            foreach ($article_property_types as $article_property_type) {
                
                $article_property = ArticleProperty::create([
                    'article_id'                => $article->id,
                    'article_property_type_id'  => $article_property_type->id
                ]);

                // $article_property->
            }
            // $article->
        }
    }

}