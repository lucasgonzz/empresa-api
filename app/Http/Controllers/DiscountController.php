<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\Discount;
use Illuminate\Http\Request;

class DiscountController extends Controller
{

    public function index() {
        $models = Discount::where('user_id', $this->userId())
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        $model = Discount::create([
            'num'                   => $this->num('discounts'),
            'name'                  => $request->name,
            'percentage'            => $request->percentage,
            'user_id'               => $this->userId(),
        ]);
        $this->sendAddModelNotification('Discount', $model->id);
        return response()->json(['model' => $this->fullModel('Discount', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('Discount', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = Discount::find($id);
        $model->name                = $request->name;
        $model->percentage          = $request->percentage;
        $model->save();
        $this->sendAddModelNotification('Discount', $model->id);
        return response()->json(['model' => $this->fullModel('Discount', $model->id)], 200);
    }

    public function destroy($id) {
        $model = Discount::find($id);
        $model->delete();
        ImageController::deleteModelImages($model);
        $this->sendDeleteModelNotification('Discount', $model->id);
        return response(null);
    }
}
