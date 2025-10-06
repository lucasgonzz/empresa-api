<?php

namespace App\Http\Controllers;

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
}
