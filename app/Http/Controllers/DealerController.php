<?php

namespace App\Http\Controllers;

use App\Models\Dealer;
use Illuminate\Http\Request;

class DealerController extends Controller
{

    public function index() {
        $models = Dealer::where('user_id', $this->userId())
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        $model = Dealer::create([
            'name'                  => $request->name,
            'user_id'               => $this->userId(),
        ]);
        $this->sendAddModelNotification('Dealer', $model->id);
        return response()->json(['model' => $this->fullModel('Dealer', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('Dealer', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = Dealer::find($id);
        $model->name                = $request->name;
        $model->save();
        $this->sendAddModelNotification('Dealer', $model->id);
        return response()->json(['model' => $this->fullModel('Dealer', $model->id)], 200);
    }

    public function destroy($id) {
        $model = Dealer::find($id);
        $model->delete();
        ImageController::deleteModelImages($model);
        $this->sendDeleteModelNotification('Dealer', $model->id);
        return response(null);
    }
}
