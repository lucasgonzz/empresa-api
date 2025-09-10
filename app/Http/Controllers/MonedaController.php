<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\Moneda;
use Illuminate\Http\Request;

class MonedaController extends Controller
{

    public function index() {
        $models = Moneda::orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        $model = Moneda::create([
            'name'                  => $request->name,
        ]);
        $this->sendAddModelNotification('Moneda', $model->id);
        return response()->json(['model' => $this->fullModel('Moneda', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('Moneda', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = Moneda::find($id);
        $model->name                = $request->name;
        $model->save();
        return response()->json(['model' => $this->fullModel('Moneda', $model->id)], 200);
    }

    public function destroy($id) {
        $model = Moneda::find($id);
        ImageController::deleteModelImages($model);
        $model->delete();
        $this->sendDeleteModelNotification('Moneda', $model->id);
        return response(null);
    }
}
