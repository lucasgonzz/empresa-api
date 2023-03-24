<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\Brand;
use Illuminate\Http\Request;

class BrandController extends Controller
{

    public function index() {
        $models = Brand::where('user_id', $this->userId())
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        $model = Brand::create([
            'num'                   => $this->num('brands'),
            'name'                  => $request->name,
            'user_id'               => $this->userId(),
        ]);
        $this->sendAddModelNotification('Brand', $model->id);
        return response()->json(['model' => $this->fullModel('Brand', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('Brand', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = Brand::find($id);
        $model->name                = $request->name;
        $model->save();
        $this->sendAddModelNotification('Brand', $model->id);
        return response()->json(['model' => $this->fullModel('Brand', $model->id)], 200);
    }

    public function destroy($id) {
        $model = Brand::find($id);
        $model->delete();
        ImageController::deleteModelImages($model);
        $this->sendDeleteModelNotification('Brand', $model->id);
        return response(null);
    }
}
