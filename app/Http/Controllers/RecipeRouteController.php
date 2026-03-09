<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\RecipeRoute;
use Illuminate\Http\Request;

class RecipeRouteController extends Controller
{

    public function index() {
        $models = RecipeRoute::where('user_id', $this->userId())
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }


    public function store(Request $request) {
        $model = RecipeRoute::create([
            'recipe_id'                 => $request->recipe_id,
            'recipe_route_type_id'      => $request->recipe_route_type_id,
            'from_address_id'           => $request->from_address_id,
            'to_address_id'             => $request->to_address_id,
            'temporal_id'               => $this->getTemporalId($request),
            'recipe_id'                 => $request->model_id,
        ]);

        GeneralHelper::attachModels($model, 'articles', $request->articles, ['amount', 'notes', 'order_production_status_id', 'address_id']);

        return response()->json(['model' => $this->fullModel('RecipeRoute', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('RecipeRoute', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = RecipeRoute::find($id);
        $model->recipe_route_type_id      = $request->recipe_route_type_id;
        $model->from_address_id           = $request->from_address_id;
        $model->to_address_id             = $request->to_address_id;
        $model->save();

        GeneralHelper::attachModels($model, 'articles', $request->articles, ['amount', 'notes', 'order_production_status_id', 'address_id']);
        
        return response()->json(['model' => $this->fullModel('RecipeRoute', $model->id)], 200);
    }

    public function destroy($id) {
        $model = RecipeRoute::find($id);
        ImageController::deleteModelImages($model);
        $model->delete();
        $this->sendDeleteModelNotification('RecipeRoute', $model->id);
        return response(null);
    }
}
