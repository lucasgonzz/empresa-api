<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Http\Controllers\Helpers\RecipeHelper;
use App\Models\Recipe;
use Illuminate\Http\Request;

class RecipeController extends Controller
{

    public function index() {
        $models = Recipe::where('user_id', $this->userId())
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        $model = Recipe::create([
            'num'                   => $this->num('recipes'),
            'article_id'            => $request->article_id,
            'user_id'               => $this->userId(),
        ]);
        RecipeHelper::attachArticles($model, $request->articles);
        $this->sendAddModelNotification('Recipe', $model->id);
        return response()->json(['model' => $this->fullModel('Recipe', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('Recipe', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = Recipe::find($id);
        $model->article_id       = $request->article_id;
        $model->save();
        RecipeHelper::attachArticles($model, $request->articles);
        $this->sendAddModelNotification('Recipe', $model->id);
        return response()->json(['model' => $this->fullModel('Recipe', $model->id)], 200);
    }

    public function destroy($id) {
        $model = Recipe::find($id);
        $model->delete();
        ImageController::deleteModelImages($model);
        $this->sendDeleteModelNotification('Recipe', $model->id);
        return response(null);
    }
}
