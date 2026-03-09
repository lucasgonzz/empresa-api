<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\RecipeRouteType;
use Illuminate\Http\Request;

class RecipeRouteTypeController extends Controller
{

    public function index() {
        $models = RecipeRouteType::where('user_id', $this->userId())
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }



    public function store(Request $request) {
        $model = RecipeRouteType::create([
            'name'                  => $request->name,
            'user_id'               => $this->userId(),
        ]);
        $this->sendAddModelNotification('RecipeRouteType', $model->id);
        return response()->json(['model' => $this->fullModel('RecipeRouteType', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('RecipeRouteType', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = RecipeRouteType::find($id);
        $model->name                = $request->name;
        $model->save();
        $this->sendAddModelNotification('RecipeRouteType', $model->id);
        return response()->json(['model' => $this->fullModel('RecipeRouteType', $model->id)], 200);
    }

    public function destroy($id) {
        $model = RecipeRouteType::find($id);
        ImageController::deleteModelImages($model);
        $model->delete();
        $this->sendDeleteModelNotification('RecipeRouteType', $model->id);
        return response(null);
    }
}
