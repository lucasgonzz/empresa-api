<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\Category;
use Illuminate\Http\Request;

class CategoryController extends Controller
{

    public function index() {
        $models = Category::where('user_id', $this->userId())
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        $model = Category::create([
            'num'                   => $this->num('categories'),
            'name'                  => $request->name,
            'user_id'               => $this->userId(),
        ]);
        $this->sendAddModelNotification('Category', $model->id);
        return response()->json(['model' => $this->fullModel('Category', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('Category', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = Category::find($id);
        $model->name                = $request->name;
        $model->save();
        $this->sendAddModelNotification('Category', $model->id);
        return response()->json(['model' => $this->fullModel('Category', $model->id)], 200);
    }

    public function destroy($id) {
        $model = Category::find($id);
        $model->delete();
        ImageController::deleteModelImages($model);
        $this->sendDeleteModelNotification('Category', $model->id);
        return response(null);
    }
}
