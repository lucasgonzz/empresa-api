<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\ConceptoMovimientoCaja;
use Illuminate\Http\Request;

class ConceptoMovimientoCajaController extends Controller
{

    public function index() {
        $models = ConceptoMovimientoCaja::orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        $model = ConceptoMovimientoCaja::create([
            'num'                   => $this->num('ConceptoMovimientoCaja'),
            'name'                  => $request->name,
            'user_id'               => $this->userId(),
        ]);
        $this->sendAddModelNotification('ConceptoMovimientoCaja', $model->id);
        return response()->json(['model' => $this->fullModel('ConceptoMovimientoCaja', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('ConceptoMovimientoCaja', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = ConceptoMovimientoCaja::find($id);
        $model->name                = $request->name;
        $model->save();
        $this->sendAddModelNotification('ConceptoMovimientoCaja', $model->id);
        return response()->json(['model' => $this->fullModel('ConceptoMovimientoCaja', $model->id)], 200);
    }

    public function destroy($id) {
        $model = ConceptoMovimientoCaja::find($id);
        ImageController::deleteModelImages($model);
        $model->delete();
        $this->sendDeleteModelNotification('ConceptoMovimientoCaja', $model->id);
        return response(null);
    }
}
