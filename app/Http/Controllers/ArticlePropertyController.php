<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Models\ArticleProperty;
use Illuminate\Http\Request;

class ArticlePropertyController extends Controller
{
    function store(Request $request) {
        $model = ArticleProperty::create([
            'article_id'                    => $request->article_id,
            'article_property_type_id'      => $request->article_property_type_id,
        ]);
        GeneralHelper::attachModels($model, 'article_property_values', $request->article_property_values);
        return response()->json(['model' => $this->fullModel('ArticleProperty', $model->id)], 201);
    }

    function update(Request $request, $id) {
        $model = ArticleProperty::find($id);
        $model->article_property_type_id = $request->article_property_type_id;
        $model->save();
        GeneralHelper::attachModels($model, 'article_property_values', $request->article_property_values);
        return response()->json(['model' => $this->fullModel('ArticleProperty', $model->id)], 200);
    }
}
