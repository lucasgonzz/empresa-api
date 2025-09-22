<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\ClientReputation;
use Illuminate\Http\Request;

class ClientReputationController extends Controller
{

    public function index() {
        $models = ClientReputation::where('user_id', $this->userId())
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        $model = ClientReputation::create([
            'name'                  => $request->name,
            'user_id'               => $this->userId(),
        ]);
        // $this->sendAddModelNotification('ClientReputation', $model->id);
        return response()->json(['model' => $this->fullModel('ClientReputation', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('ClientReputation', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = ClientReputation::find($id);
        $model->name                = $request->name;
        $model->save();
        // $this->sendAddModelNotification('ClientReputation', $model->id);
        return response()->json(['model' => $this->fullModel('ClientReputation', $model->id)], 200);
    }

    public function destroy($id) {
        $model = ClientReputation::find($id);
        ImageController::deleteModelImages($model);
        $model->delete();
        $this->sendDeleteModelNotification('ClientReputation', $model->id);
        return response(null);
    }
}
