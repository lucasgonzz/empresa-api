<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Models\ArticleSurchageBlanco;
use Illuminate\Http\Request;

class ArticleSurchageBlancoController extends Controller
{

    public function index() {
        $models = ArticleSurchageBlanco::where('user_id', $this->userId())
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        $model = ArticleSurchageBlanco::create([
            'article_id'            => $request->model_id,
            'temporal_id'           => $this->getTemporalId($request),
            'percentage'            => $request->percentage,
        ]);
        if (!is_null($request->model_id)) {
            ArticleHelper::setFinalPrice($model->article);
            $this->sendAddModelNotification('article', $model->article_id, false);
        }
        return response()->json(['model' => $this->fullModel('ArticleSurchageBlanco', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('ArticleSurchageBlanco', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = ArticleSurchageBlanco::find($id);
        $model->percentage                = $request->percentage;
        $model->save();
        ArticleHelper::setFinalPrice($model->article);
        $this->sendAddModelNotification('article', $model->article_id, false);
        return response()->json(['model' => $this->fullModel('ArticleSurchageBlanco', $model->id)], 200);
    }

    public function destroy($id) {
        $model = ArticleSurchageBlanco::find($id);
        $model->delete();
        ImageController::deleteModelImages($model);
        $this->sendDeleteModelNotification('ArticleSurchageBlanco', $model->id);
        return response(null);
    }
}
