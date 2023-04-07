<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\Description;
use Illuminate\Http\Request;

class DescriptionController extends Controller
{

    public function index() {
        $models = Description::where('user_id', $this->userId())
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        $model = Description::create([
            'title'                 => $request->title,
            'content'               => $request->content,
            // 'user_id'               => $this->userId(),
        ]);
        $this->sendAddModelNotification('Description', $model->id);
        return response()->json(['model' => $this->fullModel('Description', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('Description', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = Description::find($id);
        $model->title                = $request->title;
        $model->content              = $request->content;
        $model->save();
        $this->sendAddModelNotification('Description', $model->id);
        return response()->json(['model' => $this->fullModel('Description', $model->id)], 200);
    }

    public function destroy($id) {
        $model = Description::find($id);
        $model->delete();
        ImageController::deleteModelImages($model);
        $this->sendDeleteModelNotification('Description', $model->id);
        return response(null);
    }
}
