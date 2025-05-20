<?php

namespace App\Http\Controllers;

use App\Models\Cepa;
use Illuminate\Http\Request;

class CepaController extends Controller
{

    public function index() {
        $models = Cepa::where('user_id', $this->userId())
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        $model = Cepa::create([
            'name'                  => $request->name,
            'user_id'               => $this->userId(),
        ]);
        $this->sendAddModelNotification('Cepa', $model->id);
        return response()->json(['model' => $this->fullModel('Cepa', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('Cepa', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = Cepa::find($id);
        $model->name                = $request->name;
        $model->save();
        $this->sendAddModelNotification('Cepa', $model->id);
        return response()->json(['model' => $this->fullModel('Cepa', $model->id)], 200);
    }

    public function destroy($id) {
        $model = Cepa::find($id);
        $model->delete();
        ImageController::deleteModelImages($model);
        $this->sendDeleteModelNotification('Cepa', $model->id);
        return response(null);
    }
}
