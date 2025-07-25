<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Models\ArticleDiscount;
use Illuminate\Http\Request;

class ArticleDiscountController extends Controller
{

    public function index() {
        $models = ArticleDiscount::where('user_id', $this->userId())
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        $model = ArticleDiscount::create([
            'article_id'            => $request->model_id,
            'temporal_id'           => $this->getTemporalId($request),
            'percentage'            => $request->percentage,
            'amount'                => $request->amount,
            'show_in_online'        => $request->show_in_online,
        ]);
        if (!is_null($request->model_id)) {
            ArticleHelper::setFinalPrice($model->article);
            $this->sendAddModelNotification('article', $model->article_id, false);
        }
        return response()->json(['model' => $this->fullModel('ArticleDiscount', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('ArticleDiscount', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = ArticleDiscount::find($id);
        $model->percentage                = $request->percentage;
        $model->amount                    = $request->amount;
        $model->show_in_online            = $request->show_in_online;
        $model->save();
        ArticleHelper::setFinalPrice($model->article);
        $this->sendAddModelNotification('article', $model->article_id, false);
        return response()->json(['model' => $this->fullModel('ArticleDiscount', $model->id)], 200);
    }

    public function destroy($id) {
        $model = ArticleDiscount::find($id);
        $model->delete();
        ImageController::deleteModelImages($model);
        $this->sendDeleteModelNotification('ArticleDiscount', $model->id);
        return response(null);
    }
}
