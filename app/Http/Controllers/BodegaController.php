<?php

namespace App\Http\Controllers;

use App\Models\Bodega;
use Illuminate\Http\Request;

class BodegaController extends Controller
{

    public function index() {
        $models = Bodega::where('user_id', $this->userId())
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        $model = Bodega::create([
            'name'                  => $request->name,
            'user_id'               => $this->userId(),
        ]);
        $this->sendAddModelNotification('Bodega', $model->id);
        return response()->json(['model' => $this->fullModel('Bodega', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('Bodega', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = Bodega::find($id);
        $model->name                = $request->name;
        $model->save();
        $this->sendAddModelNotification('Bodega', $model->id);
        return response()->json(['model' => $this->fullModel('Bodega', $model->id)], 200);
    }

    public function destroy($id) {
        $model = Bodega::find($id);
        $model->delete();
        ImageController::deleteModelImages($model);
        $this->sendDeleteModelNotification('Bodega', $model->id);
        return response(null);
    }
}
