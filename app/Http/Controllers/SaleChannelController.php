<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\SaleChannel;
use Illuminate\Http\Request;

class SaleChannelController extends Controller
{

    public function index() {
        
        $models = SaleChannel::orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();

        return response()->json(['models' => $models], 200);
    }

    // public function store(Request $request) {
    //     $model = SaleChannel::create([
    //         'num'                   => $this->num('SaleChannel'),
    //         'name'                  => $request->name,
    //         'user_id'               => $this->userId(),
    //     ]);
    //     $this->sendAddModelNotification('SaleChannel', $model->id);
    //     return response()->json(['model' => $this->fullModel('SaleChannel', $model->id)], 201);
    // }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('SaleChannel', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = SaleChannel::find($id);
        $model->name                = $request->name;
        $model->save();
        $this->sendAddModelNotification('SaleChannel', $model->id);
        return response()->json(['model' => $this->fullModel('SaleChannel', $model->id)], 200);
    }

    public function destroy($id) {
        $model = SaleChannel::find($id);
        ImageController::deleteModelImages($model);
        $model->delete();
        $this->sendDeleteModelNotification('SaleChannel', $model->id);
        return response(null);
    }
}
