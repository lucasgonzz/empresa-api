<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\Size;
use Illuminate\Http\Request;

class SizeController extends Controller
{

    public function index() {
        $models = Size::where('user_id', $this->userId())
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        $model = Size::create([
            'num'                   => $this->num('Sizes'),
            'value'                  => $request->value,
            'user_id'               => $this->userId(),
        ]);
        $this->sendAddModelNotification('sizes', $model->id);
        return response()->json(['model' => $this->fullModel('Size', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('Size', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = Size::find($id);
        $model->value                = $request->value;
        $model->save();
        $this->sendAddModelNotification('sizes', $model->id);
        return response()->json(['model' => $this->fullModel('Size', $model->id)], 200);
    }

    public function destroy($id) {
        $model = Size::find($id);
        $model->delete();
        ImageController::deleteModelImages($model);
        $this->sendDeleteModelNotification('Size', $model->id);
        return response(null);
    }
}
