<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\Provincia;
use Illuminate\Http\Request;

class ProvinciaController extends Controller
{

    public function index() {
        $models = Provincia::where('user_id', $this->userId())
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }


    public function store(Request $request) {
        $model = Provincia::create([
            'name'                  => $request->name,
            'user_id'               => $this->userId(),
        ]);
        // $this->sendAddModelNotification('Provincia', $model->id);
        return response()->json(['model' => $this->fullModel('Provincia', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('Provincia', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = Provincia::find($id);
        $model->name                = $request->name;
        $model->save();
        // $this->sendAddModelNotification('Provincia', $model->id);
        return response()->json(['model' => $this->fullModel('Provincia', $model->id)], 200);
    }

    public function destroy($id) {
        $model = Provincia::find($id);
        ImageController::deleteModelImages($model);
        $model->delete();
        $this->sendDeleteModelNotification('Provincia', $model->id);
        return response(null);
    }
}
