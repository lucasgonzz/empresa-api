<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\IvaCondition;
use Illuminate\Http\Request;

class IvaConditionController extends Controller
{

    public function index() {
        $models = IvaCondition::orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    // public function store(Request $request) {
    //     $model = IvaCondition::create([
    //         'num'                   => $this->num('IvaCondition'),
    //         'name'                  => $request->name,
    //         'user_id'               => $this->userId(),
    //     ]);
    //     $this->sendAddModelNotification('IvaCondition', $model->id);
    //     return response()->json(['model' => $this->fullModel('IvaCondition', $model->id)], 201);
    // }  

    // public function show($id) {
    //     return response()->json(['model' => $this->fullModel('IvaCondition', $id)], 200);
    // }

    // public function update(Request $request, $id) {
    //     $model = IvaCondition::find($id);
    //     $model->name                = $request->name;
    //     $model->save();
    //     $this->sendAddModelNotification('IvaCondition', $model->id);
    //     return response()->json(['model' => $this->fullModel('IvaCondition', $model->id)], 200);
    // }

    // public function destroy($id) {
    //     $model = IvaCondition::find($id);
    //     $model->delete();
    //     ImageController::deleteModelImages($model);
    //     $this->sendDeleteModelNotification('IvaCondition', $model->id);
    //     return response(null);
    // }
}
