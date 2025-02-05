<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\CategoryPriceTypeRange;
use Illuminate\Http\Request;

class CategoryPriceTypeRangeController extends Controller
{

    public function index() {
        $models = CategoryPriceTypeRange::where('user_id', $this->userId())
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        $model = CategoryPriceTypeRange::create([
            'category_id'           => $request->category_id,
            'sub_category_id'       => $request->sub_category_id,
            'price_type_id'         => $request->price_type_id,
            'min'                   => $request->min,
            'max'                   => $request->max,
            'user_id'               => $this->userId(),
        ]);
        $this->sendAddModelNotification('CategoryPriceTypeRange', $model->id);
        return response()->json(['model' => $this->fullModel('CategoryPriceTypeRange', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('CategoryPriceTypeRange', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = CategoryPriceTypeRange::find($id);
        $model->category_id         = $request->category_id;
        $model->sub_category_id     = $request->sub_category_id;
        $model->price_type_id       = $request->price_type_id;
        $model->min                 = $request->min;
        $model->max                 = $request->max;
        $model->save();

        $this->sendAddModelNotification('CategoryPriceTypeRange', $model->id);
        
        return response()->json(['model' => $this->fullModel('CategoryPriceTypeRange', $model->id)], 200);
    }

    public function destroy($id) {
        $model = CategoryPriceTypeRange::find($id);
        ImageController::deleteModelImages($model);
        $model->delete();
        $this->sendDeleteModelNotification('CategoryPriceTypeRange', $model->id);
        return response(null);
    }
}
