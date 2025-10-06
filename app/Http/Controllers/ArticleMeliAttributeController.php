<?php

namespace App\Http\Controllers;

use App\Models\ArticleMeliAttribute;
use Illuminate\Http\Request;

class ArticleMeliAttributeController extends Controller
{

    function show($article_id) {
        $models = ArticleMeliAttribute::where('article_id', $article_id)
                                    ->withAll()
                                    ->get();

        return response()->json(['models'    => $models], 200);
    }

    function update(Request $request, $id) {
        
        $model = ArticleMeliAttribute::find($id);

        $model->meli_attribute_value_id     = $request->meli_attribute_value_id;
        $model->value_id              = $request->value_id;
        $model->value_name            = $request->value_name;

        $model->save();

        return response()->json(['model'    => $model], 200);
    }

    function store(Request $request) {
        $model = ArticleMeliAttribute::create([
            'article_id'            => $request->article_id,
            'meli_attribute_id'     => $request->meli_attribute_id,
            'meli_attribute_value_id'     => $request->meli_attribute_value_id,
            'value_id'              => $request->value_id,
            'value_name'            => $request->value_name,
        ]);
        return response()->json(['model'    => $model], 201);
    }
}
