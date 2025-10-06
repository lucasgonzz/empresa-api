<?php

namespace App\Services\MercadoLibre;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Models\Article;
use App\Models\Description;
use App\Models\Image;
use App\Models\PriceType;
use App\Services\MercadoLibre\MercadoLibreService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SetearCategoryNameService extends MercadoLibreService
{

    function __construct($user_id = null) {
        parent::__construct($user_id);

        $this->user_id = $user_id;   
    }

    public function setear_category_name($article_id)
    {
        $articles = Article::where('id', $article_id)
                                ->get();


        foreach ($articles as $article) {
            
            if ($article->meli_category_id) {

                $response = $this->make_request('get', "categories/{$article->meli_category_id}");

                Log::info('response categoria:');
                Log::info($response);


                $response = $this->make_request('get', "categories/{$article->meli_category_id}/attributes");

                Log::info('response attributes:');
                Log::info($response);
                // $article->meli_category_name = 
            }
        }
    }
}
