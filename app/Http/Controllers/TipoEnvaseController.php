<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\TipoEnvase;
use Illuminate\Http\Request;

class TipoEnvaseController extends Controller
{

    public function index() {
        $models = TipoEnvase::where('user_id', $this->userId())
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        $model = TipoEnvase::create([
            'name'                  => $request->name,
            'user_id'               => $this->userId(),
        ]);
        $this->sendAddModelNotification('TipoEnvase', $model->id);
        return response()->json(['model' => $this->fullModel('TipoEnvase', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('TipoEnvase', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = TipoEnvase::find($id);
        $model->name                = $request->name;
        $model->save();
        $this->sendAddModelNotification('TipoEnvase', $model->id);
        return response()->json(['model' => $this->fullModel('TipoEnvase', $model->id)], 200);
    }

    public function destroy($id) {
        $model = TipoEnvase::find($id);
        ImageController::deleteModelImages($model);
        $model->delete();
        $this->sendDeleteModelNotification('TipoEnvase', $model->id);
        return response(null);
    }
}
