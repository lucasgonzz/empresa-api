<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\Location;
use Illuminate\Http\Request;

class LocationController extends Controller
{

    public function index() {
        $models = Location::where('user_id', $this->userId())
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        $model = Location::create([
            'num'                   => $this->num('locations'),
            'name'                  => $request->name,
            'user_id'               => $this->userId(),
        ]);
        $this->sendAddModelNotification('Location', $model->id);
        return response()->json(['model' => $this->fullModel('Location', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('Location', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = Location::find($id);
        $model->name                = $request->name;
        $model->save();
        $this->sendAddModelNotification('Location', $model->id);
        return response()->json(['model' => $this->fullModel('Location', $model->id)], 200);
    }

    public function destroy($id) {
        $model = Location::find($id);
        $model->delete();
        ImageController::deleteModelImages($model);
        $this->sendDeleteModelNotification('Location', $model->id);
        return response(null);
    }
}
