<?php

namespace App\Http\Controllers;

use App\Models\PriceTypeSurchage;
use Illuminate\Http\Request;

class PriceTypeSurchageController extends Controller
{

    public function index() {
        $models = PriceTypeSurchage::where('user_id', $this->userId())
                            ->orderBy('position', 'ASC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        $model = PriceTypeSurchage::create([
            'name'                  => $request->name,
            'percentage'            => $request->percentage,
            'amount'                => $request->amount,
            'position'              => $request->position,
            'price_type_id'         => $request->model_id,
            'temporal_id'           => $this->getTemporalId($request),
        ]);
        return response()->json(['model' => $this->fullModel('PriceTypeSurchage', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('PriceTypeSurchage', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = PriceTypeSurchage::find($id);
        $model->name                  = $request->name;
        $model->percentage            = $request->percentage;
        $model->amount                = $request->amount;
        $model->position              = $request->position;
        $model->save();
        return response()->json(['model' => $this->fullModel('PriceTypeSurchage', $model->id)], 200);
    }

    public function destroy($id) {
        $model = PriceTypeSurchage::find($id);
        $model->delete();
        ImageController::deleteModelImages($model);
        $this->sendDeleteModelNotification('PriceTypeSurchage', $model->id);
        return response(null);
    }
}
