<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\Tag;
use Illuminate\Http\Request;

class TagController extends Controller
{

    public function index() {
        $models = Tag::where('user_id', $this->userId())
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        $model = Tag::create([
            'name'                  => $request->name,
            'user_id'               => $this->userId(),
        ]);
        return response()->json(['model' => $this->fullModel('Tag', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('Tag', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = Tag::find($id);
        $model->name                = $request->name;
        $model->save();
        return response()->json(['model' => $this->fullModel('Tag', $model->id)], 200);
    }

    public function destroy($id) {
        $model = Tag::find($id);
        ImageController::deleteModelImages($model);
        $model->delete();
        $this->sendDeleteModelNotification('Tag', $model->id);
        return response(null);
    }
}
