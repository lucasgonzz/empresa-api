<?php

namespace App\Http\Controllers;

use App\Models\Article;
use App\Models\MeliCategory;
use App\Services\MercadoLibre\CategoryService;
use Illuminate\Http\Request;

class MeliCategoryController extends Controller
{
    function show($id) {
        $model = MeliCategory::where('id', $id)
                                ->withAll()
                                ->first();

        return response()->json(['model'    => $model], 200);
    }

    function category_predictor($article_name) {
        
        $service = new CategoryService($this->userId());

        $categories = $service->fetch_meli_categories($article_name);

        return response()->json(['categories'    => $categories], 200);
    }

    function asignar_meli_category($article_id, $mercado_libre_category_id) {
        $article = Article::find($article_id);

        $service = new CategoryService($this->userId());

        $service->assign_to_article($article, $mercado_libre_category_id);

        return response()->json(['article' => $this->fullModel('article', $article_id)], 200);
    }
}
