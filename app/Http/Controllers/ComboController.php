<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\Combo;
use Illuminate\Http\Request;

class ComboController extends Controller
{

    public function index() {
        $models = Combo::where('user_id', $this->userId())
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        $model = Combo::create([
            'num'                   => $this->num('combos'),
            'name'                  => $request->name,
            'cost'                  => $request->cost,
            'price'                 => $request->price,
            'user_id'               => $this->userId(),
        ]);

        GeneralHelper::attachModels($model, 'articles', $request->articles, ['amount']);

        return response()->json(['model' => $this->fullModel('Combo', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('Combo', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = Combo::find($id);
        $model->name                = $request->name;
        $model->cost                = $request->cost;
        $model->price               = $request->price;
        $model->save();

        GeneralHelper::attachModels($model, 'articles', $request->articles, ['amount']);
        return response()->json(['model' => $this->fullModel('Combo', $model->id)], 200);
    }

    public function destroy($id) {
        $model = Combo::find($id);
        ImageController::deleteModelImages($model);
        $model->delete();
        $this->sendDeleteModelNotification('Combo', $model->id);
        return response(null);
    }
}
