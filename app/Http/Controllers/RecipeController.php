<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Http\Controllers\Helpers\RecipeHelper;
use App\Models\Recipe;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

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
            'num'                       => $this->num('recipes'),
            'article_id'                => $request->article_id,
            'article_cost_from_recipe'  => $request->article_cost_from_recipe,
            'address_id'                => $request->address_id,
            'observations'              => $request->observations,
            'user_id'                   => $this->userId(),
        ]);
        RecipeHelper::attachArticles($model, $request->articles);
        RecipeHelper::checkCostFromRecipe($model, $this);
        $this->sendAddModelNotification('Recipe', $model->id);
        return response()->json(['model' => $this->fullModel('Recipe', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('Recipe', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = Recipe::find($id);
        $model->article_cost_from_recipe    = $request->article_cost_from_recipe;
        $model->article_id                  = $request->article_id;
        $model->address_id                  = $request->address_id;
        $model->observations                = $request->observations;
        $model->save();
        RecipeHelper::attachArticles($model, $request->articles);
        RecipeHelper::checkCostFromRecipe($model, $this);
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

    function articleUsedInRecipes($article_id) {
        $recipes = Recipe::whereHas('articles', function(Builder $query) use ($article_id) {
                                $query->where('article_id', $article_id);
                            })
                            ->get();
        $models = [];
        
        foreach ($recipes as $recipe) {

            foreach ($recipe->articles as $article) {

                if ($article->id == $article_id) {

                    $exists = false;

                    // Verifica si el modelo ya existe en el array
                    foreach ($models as $model) {

                        if ($model['recipe_id'] == $recipe->id && $model['article_id'] == $recipe->article->id) {
                            $exists = true;
                            break;
                        }
                    }

                    // Solo agrega si no existe en el array
                    if (!$exists) {

                        $models[] = [
                            'recipe_id'                 => $recipe->id,
                            'article_id'                => $recipe->article->id,
                            'article'                   => $recipe->article->name,
                            'amount'                    => $article->pivot->amount,
                            'order_production_status'   => $this->getModelBy('order_production_statuses', 'id', $article->pivot->order_production_status_id, false, 'name'),
                        ];
                    }

                }
            }
        }
        return response()->json(['models' => $models], 200);
        // return response()->json(['models' => $models, 'recipes' => $recipes], 200);
    }
}
