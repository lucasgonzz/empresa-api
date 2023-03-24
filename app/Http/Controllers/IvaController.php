<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\Iva;
use Illuminate\Http\Request;

class IvaController extends Controller
{

    public function index() {
        $models = Iva::orderBy('percentage', 'ASC')
                        ->withAll()
                        ->get();
        return response()->json(['models' => $models], 200);
    }

    // public function store(Request $request) {
    //     $model = Iva::create([
    //         'num'                   => $this->num('Iva'),
    //         'name'                  => $request->name,
    //         'user_id'               => $this->userId(),
    //     ]);
    //     $this->sendAddModelNotification('Iva', $model->id);
    //     return response()->json(['model' => $this->fullModel('Iva', $model->id)], 201);
    // }  

    // public function show($id) {
    //     return response()->json(['model' => $this->fullModel('Iva', $id)], 200);
    // }

    // public function update(Request $request, $id) {
    //     $model = Iva::find($id);
    //     $model->name                = $request->name;
    //     $model->save();
    //     $this->sendAddModelNotification('Iva', $model->id);
    //     return response()->json(['model' => $this->fullModel('Iva', $model->id)], 200);
    // }

    // public function destroy($id) {
    //     $model = Iva::find($id);
    //     $model->delete();
    //     ImageController::deleteModelImages($model);
    //     $this->sendDeleteModelNotification('Iva', $model->id);
    //     return response(null);
    // }
}
