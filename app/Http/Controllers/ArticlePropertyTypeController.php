<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\ArticlePropertyType;
use Illuminate\Http\Request;

class ArticlePropertyTypeController extends Controller
{

    public function index() {
        $models = ArticlePropertyType::where('user_id', $this->userId())
                            ->orWhereNull('user_id')
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        $model = ArticlePropertyType::create([
            'num'                   => $this->num('article_property_types'),
            'name'                  => $request->name,
            'user_id'               => $this->userId(),
        ]);
        $this->sendAddModelNotification('article_property_type', $model->id);
        return response()->json(['model' => $this->fullModel('ArticlePropertyType', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('ArticlePropertyType', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = ArticlePropertyType::find($id);
        $model->name                = $request->name;
        $model->save();
        $this->sendAddModelNotification('article_property_type', $model->id);
        return response()->json(['model' => $this->fullModel('ArticlePropertyType', $model->id)], 200);
    }

    public function destroy($id) {
        $model = ArticlePropertyType::find($id);
        ImageController::deleteModelImages($model);
        $model->delete();
        $this->sendDeleteModelNotification('ArticlePropertyType', $model->id);
        return response(null);
    }
}
