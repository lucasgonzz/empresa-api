<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\Deposit;
use Illuminate\Http\Request;

class DepositController extends Controller
{

    public function index() {
        $models = Deposit::where('user_id', $this->userId())
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        $model = Deposit::create([
            'num'                   => $this->num('deposits'),
            'name'                  => $request->name,
            'description'           => $request->description,
            'user_id'               => $this->userId(),
        ]);
        $this->sendAddModelNotification('deposits', $model->id);
        return response()->json(['model' => $this->fullModel('Deposit', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('Deposit', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = Deposit::find($id);
        $model->name                = $request->name;
        $model->description         = $request->description;
        $model->save();
        $this->sendAddModelNotification('deposits', $model->id);
        return response()->json(['model' => $this->fullModel('Deposit', $model->id)], 200);
    }

    public function destroy($id) {
        $model = Deposit::find($id);
        $model->delete();
        ImageController::deleteModelImages($model);
        $this->sendDeleteModelNotification('Deposit', $model->id);
        return response(null);
    }
}
