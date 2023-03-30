<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\Surchage;
use Illuminate\Http\Request;

class SurchageController extends Controller
{

    public function index() {
        $models = Surchage::where('user_id', $this->userId())
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        $model = Surchage::create([
            'num'                   => $this->num('surchages'),
            'name'                  => $request->name,
            'percentage'            => $request->percentage,
            'user_id'               => $this->userId(),
        ]);
        $this->sendAddModelNotification('Surchage', $model->id);
        return response()->json(['model' => $this->fullModel('Surchage', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('Surchage', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = Surchage::find($id);
        $model->name                = $request->name;
        $model->percentage          = $request->percentage;
        $model->save();
        $this->sendAddModelNotification('Surchage', $model->id);
        return response()->json(['model' => $this->fullModel('Surchage', $model->id)], 200);
    }

    public function destroy($id) {
        $model = Surchage::find($id);
        $model->delete();
        ImageController::deleteModelImages($model);
        $this->sendDeleteModelNotification('Surchage', $model->id);
        return response(null);
    }
}
