<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\TurnoCaja;
use Illuminate\Http\Request;

class TurnoCajaController extends Controller
{

    public function index() {
        $models = TurnoCaja::where('user_id', $this->userId())
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }


    public function store(Request $request) {
        $model = TurnoCaja::create([
            'name'                  => $request->name,
            'hora_inicio'                  => $request->hora_inicio,
            'hora_fin'                  => $request->hora_fin,
            'user_id'               => $this->userId(),
        ]);
        return response()->json(['model' => $this->fullModel('TurnoCaja', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('TurnoCaja', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = TurnoCaja::find($id);
        $model->name                = $request->name;
        $model->hora_inicio                = $request->hora_inicio;
        $model->hora_fin                = $request->hora_fin;
        $model->save();
        return response()->json(['model' => $this->fullModel('TurnoCaja', $model->id)], 200);
    }

    public function destroy($id) {
        $model = TurnoCaja::find($id);
        ImageController::deleteModelImages($model);
        $model->delete();
        $this->sendDeleteModelNotification('TurnoCaja', $model->id);
        return response(null);
    }
}
