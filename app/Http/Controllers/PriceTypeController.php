<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\PriceType;
use Illuminate\Http\Request;

class PriceTypeController extends Controller
{

    public function index() {
        $models = PriceType::where('user_id', $this->userId())
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        $model = PriceType::create([
            'num'                   => $this->num('price_types'),
            'name'                  => $request->name,
            'percentage'            => $request->percentage,
            'position'              => $request->position,
            'user_id'               => $this->userId(),
        ]);
        $this->sendAddModelNotification('price_type', $model->id);
        
        GeneralHelper::attachModels($model, 'sub_categories', $request->sub_categories, ['percentage']);

        return response()->json(['model' => $this->fullModel('PriceType', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('PriceType', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = PriceType::find($id);
        $model->name                = $request->name;
        $model->percentage          = $request->percentage;
        $model->position            = $request->position;
        $model->save();
        $this->sendAddModelNotification('price_type', $model->id);

        GeneralHelper::attachModels($model, 'sub_categories', $request->sub_categories, ['percentage']);
        
        return response()->json(['model' => $this->fullModel('PriceType', $model->id)], 200);
    }

    public function destroy($id) {
        $model = PriceType::find($id);
        $model->delete();
        ImageController::deleteModelImages($model);
        $this->sendDeleteModelNotification('PriceType', $model->id);
        return response(null);
    }
}
