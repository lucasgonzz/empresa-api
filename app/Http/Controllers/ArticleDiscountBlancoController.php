<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Models\ArticleDiscountBlanco;
use Illuminate\Http\Request;

class ArticleDiscountBlancoController extends Controller
{

    public function index() {
        $models = ArticleDiscountBlanco::where('user_id', $this->userId())
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        $model = ArticleDiscountBlanco::create([
            'article_id'            => $request->model_id,
            'temporal_id'           => $this->getTemporalId($request),
            'percentage'            => $request->percentage,
        ]);
        if (!is_null($request->model_id)) {
            ArticleHelper::setFinalPrice($model->article);
            $this->sendAddModelNotification('article', $model->article_id, false);
        }
        return response()->json(['model' => $this->fullModel('ArticleDiscountBlanco', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('ArticleDiscountBlanco', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = ArticleDiscountBlanco::find($id);
        $model->percentage                = $request->percentage;
        $model->save();
        ArticleHelper::setFinalPrice($model->article);
        $this->sendAddModelNotification('article', $model->article_id, false);
        return response()->json(['model' => $this->fullModel('ArticleDiscountBlanco', $model->id)], 200);
    }

    public function destroy($id) {
        $model = ArticleDiscountBlanco::find($id);
        $model->delete();
        ImageController::deleteModelImages($model);
        $this->sendDeleteModelNotification('ArticleDiscountBlanco', $model->id);
        return response(null);
    }
}
