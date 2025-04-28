<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\OnlineTemplate;
use Illuminate\Http\Request;

class OnlineTemplateController extends Controller
{

    public function index() {
        $models = OnlineTemplate::orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }


    public function store(Request $request) {
        $model = OnlineTemplate::create([
            'num'                   => $this->num('OnlineTemplate'),
            'name'                  => $request->name,
            'user_id'               => $this->userId(),
        ]);
        $this->sendAddModelNotification('OnlineTemplate', $model->id);
        return response()->json(['model' => $this->fullModel('OnlineTemplate', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('OnlineTemplate', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = OnlineTemplate::find($id);
        $model->name                = $request->name;
        $model->save();
        $this->sendAddModelNotification('OnlineTemplate', $model->id);
        return response()->json(['model' => $this->fullModel('OnlineTemplate', $model->id)], 200);
    }

    public function destroy($id) {
        $model = OnlineTemplate::find($id);
        ImageController::deleteModelImages($model);
        $model->delete();
        $this->sendDeleteModelNotification('OnlineTemplate', $model->id);
        return response(null);
    }
}
