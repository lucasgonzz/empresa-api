<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\Color;
use Illuminate\Http\Request;

class ColorController extends Controller
{

    public function index() {
        $models = Color::where('user_id', $this->userId())
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        $model = Color::create([
            'num'                   => $this->num('colors'),
            'name'                  => $request->name,
            'value'                 => $request->value,
            'user_id'               => $this->userId(),
        ]);
        $this->sendAddModelNotification('Color', $model->id);
        return response()->json(['model' => $this->fullModel('Color', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('Color', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = Color::find($id);
        $model->name                = $request->name;
        $model->value               = $request->value;
        $model->save();
        $this->sendAddModelNotification('Color', $model->id);
        return response()->json(['model' => $this->fullModel('Color', $model->id)], 200);
    }

    public function destroy($id) {
        $model = Color::find($id);
        $model->delete();
        ImageController::deleteModelImages($model);
        $this->sendDeleteModelNotification('Color', $model->id);
        return response(null);
    }
}
