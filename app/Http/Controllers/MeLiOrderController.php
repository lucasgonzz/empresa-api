<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\MeLiOrder;
use Illuminate\Http\Request;

class MeLiOrderController extends Controller
{

    public function index($from_date = null, $until_date = null) {
        $models = MeLiOrder::where('user_id', $this->userId())
                        ->orderBy('created_at', 'DESC')
                        ->withAll();
        if (!is_null($from_date)) {
            if (!is_null($until_date)) {
                $models = $models->whereDate('created_at', '>=', $from_date)
                                ->whereDate('created_at', '<=', $until_date);
            } else {
                $models = $models->whereDate('created_at', $from_date);
            }
        }

        $models = $models->get();
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        $model = MeLiOrder::create([
            'num'                   => $this->num('MeLiOrder'),
            'name'                  => $request->name,
            'user_id'               => $this->userId(),
        ]);
        $this->sendAddModelNotification('MeLiOrder', $model->id);
        return response()->json(['model' => $this->fullModel('MeLiOrder', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('MeLiOrder', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = MeLiOrder::find($id);
        $model->name                = $request->name;
        $model->save();
        $this->sendAddModelNotification('MeLiOrder', $model->id);
        return response()->json(['model' => $this->fullModel('MeLiOrder', $model->id)], 200);
    }

    public function destroy($id) {
        $model = MeLiOrder::find($id);
        ImageController::deleteModelImages($model);
        $model->delete();
        $this->sendDeleteModelNotification('MeLiOrder', $model->id);
        return response(null);
    }
}
