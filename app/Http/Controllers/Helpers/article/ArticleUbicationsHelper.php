<?php

namespace App\Http\Controllers\Helpers\article;

use App\Http\Controllers\Helpers\UserHelper;
use App\Models\ArticleUbication;
use Illuminate\Support\Facades\Log;

class ArticleUbicationsHelper {

    static function init_ubications($article) {
        $ubications = ArticleUbication::where('user_id', UserHelper::userId())
                                        ->get();

            
        foreach ($ubications as $ubication) {
            
            $article->article_ubications()->attach($ubication->id);
        }
    }

}