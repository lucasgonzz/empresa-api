<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\Condition;
use Illuminate\Http\Request;

class ConditionController extends Controller
{

    public function index() {
        $models = Condition::where('user_id', $this->userId())
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        $model = Condition::create([
            'num'                   => $this->num('conditions'),
            'name'                  => $request->name,
            'description'           => $request->description,
            'user_id'               => $this->userId(),
        ]);
        $this->sendAddModelNotification('Condition', $model->id);
        return response()->json(['model' => $this->fullModel('Condition', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('Condition', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = Condition::find($id);
        $model->name                = $request->name;
        $model->description         = $request->description;
        $model->save();
        $this->sendAddModelNotification('Condition', $model->id);
        return response()->json(['model' => $this->fullModel('Condition', $model->id)], 200);
    }

    public function destroy($id) {
        $model = Condition::find($id);
        $model->delete();
        ImageController::deleteModelImages($model);
        $this->sendDeleteModelNotification('Condition', $model->id);
        return response(null);
    }
}
