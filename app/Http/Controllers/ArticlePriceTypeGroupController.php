<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\ArticlePriceTypeGroup;
use Illuminate\Http\Request;

class ArticlePriceTypeGroupController extends Controller
{

    public function index() {
        $models = ArticlePriceTypeGroup::where('user_id', $this->userId())
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }


    public function store(Request $request) {
        $model = ArticlePriceTypeGroup::create([
            'user_id'                  => $request->user_id,
            'user_id'               => $this->userId(),
        ]);

        GeneralHelper::attachModels($model, 'articles', $request->articles);

        $this->sendAddModelNotification('ArticlePriceTypeGroup', $model->id);
        return response()->json(['model' => $this->fullModel('ArticlePriceTypeGroup', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('ArticlePriceTypeGroup', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = ArticlePriceTypeGroup::find($id);

        GeneralHelper::attachModels($model, 'articles', $request->articles);

        $this->sendAddModelNotification('ArticlePriceTypeGroup', $model->id);
        return response()->json(['model' => $this->fullModel('ArticlePriceTypeGroup', $model->id)], 200);
    }

    public function destroy($id) {
        $model = ArticlePriceTypeGroup::find($id);
        ImageController::deleteModelImages($model);
        $model->delete();
        $this->sendDeleteModelNotification('ArticlePriceTypeGroup', $model->id);
        return response(null);
    }
}
