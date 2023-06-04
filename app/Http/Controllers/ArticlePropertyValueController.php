<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\ArticlePropertyValue;
use Illuminate\Http\Request;

class ArticlePropertyValueController extends Controller
{

    public function index() {
        $models = ArticlePropertyValue::where('user_id', $this->userId())
                            ->orWhereNull('user_id')
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        $model = ArticlePropertyValue::create([
            'num'                       => $this->num('article_property_values'),
            'name'                      => $request->name,
            'article_property_type_id'  => $request->article_property_type_id,
            'user_id'                   => $this->userId(),
        ]);
        $this->sendAddModelNotification('article_property_type', $model->id);
        return response()->json(['model' => $this->fullModel('ArticlePropertyValue', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('ArticlePropertyValue', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = ArticlePropertyValue::find($id);
        $model->name                        = $request->name;
        $model->article_property_type_id    = $request->article_property_type_id;
        $model->save();
        $this->sendAddModelNotification('article_property_type', $model->id);
        return response()->json(['model' => $this->fullModel('ArticlePropertyValue', $model->id)], 200);
    }

    public function destroy($id) {
        $model = ArticlePropertyValue::find($id);
        ImageController::deleteModelImages($model);
        $model->delete();
        $this->sendDeleteModelNotification('ArticlePropertyValue', $model->id);
        return response(null);
    }
}
