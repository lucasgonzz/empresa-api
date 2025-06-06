<?php

namespace App\Http\Controllers;

use App\Models\MODEL_NAME;
use Illuminate\Http\Request;

class MODEL_NAME Controller extends Controller
{

    public function index() {
        $models = MODEL_NAME::where('user_id', $this->userId())
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        $model = MODEL_NAME::create([
            'num'                   => $this->num(''),
            'name'                  => $request->name,
            'user_id'               => $this->userId(),
        ]);
        $this->sendAddModelNotification('MODEL_NAME', $model->id);
        return response()->json(['model' => $this->fullModel('MODEL_NAME', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('MODEL_NAME', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = MODEL_NAME::find($id);
        $model->name                = $request->name;
        $model->save();
        $this->sendAddModelNotification('MODEL_NAME', $model->id);
        return response()->json(['model' => $this->fullModel('MODEL_NAME', $model->id)], 200);
    }

    public function destroy($id) {
        $model = MODEL_NAME::find($id);
        $model->delete();
        ImageController::deleteModelImages($model);
        $this->sendDeleteModelNotification('MODEL_NAME', $model->id);
        return response(null);
    }
}
