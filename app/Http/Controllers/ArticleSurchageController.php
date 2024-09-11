<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Models\ArticleSurchage;
use Illuminate\Http\Request;

class ArticleSurchageController extends Controller
{

    public function index() {
        $models = ArticleSurchage::where('user_id', $this->userId())
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        $model = ArticleSurchage::create([
            'article_id'            => $request->model_id,
            'temporal_id'           => $this->getTemporalId($request),
            'percentage'            => $request->percentage,
        ]);
        if (!is_null($request->model_id)) {
            ArticleHelper::setFinalPrice($model->article);
            $this->sendAddModelNotification('article', $model->article_id, false);
        }
        return response()->json(['model' => $this->fullModel('ArticleSurchage', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('ArticleSurchage', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = ArticleSurchage::find($id);
        $model->percentage                = $request->percentage;
        $model->save();
        ArticleHelper::setFinalPrice($model->article);
        $this->sendAddModelNotification('article', $model->article_id, false);
        return response()->json(['model' => $this->fullModel('ArticleSurchage', $model->id)], 200);
    }

    public function destroy($id) {
        $model = ArticleSurchage::find($id);
        $model->delete();
        ImageController::deleteModelImages($model);
        $this->sendDeleteModelNotification('ArticleSurchage', $model->id);
        return response(null);
    }
}
