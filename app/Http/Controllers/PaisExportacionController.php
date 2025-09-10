<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\PaisExportacion;
use Illuminate\Http\Request;

class PaisExportacionController extends Controller
{

    public function index() {
        $models = PaisExportacion::orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        $model = PaisExportacion::create([
            'num'                   => $this->num('PaisExportacion'),
            'name'                  => $request->name,
            'user_id'               => $this->userId(),
        ]);
        $this->sendAddModelNotification('PaisExportacion', $model->id);
        return response()->json(['model' => $this->fullModel('PaisExportacion', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('PaisExportacion', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = PaisExportacion::find($id);
        $model->name                = $request->name;
        $model->save();
        $this->sendAddModelNotification('PaisExportacion', $model->id);
        return response()->json(['model' => $this->fullModel('PaisExportacion', $model->id)], 200);
    }

    public function destroy($id) {
        $model = PaisExportacion::find($id);
        ImageController::deleteModelImages($model);
        $model->delete();
        $this->sendDeleteModelNotification('PaisExportacion', $model->id);
        return response(null);
    }
}
