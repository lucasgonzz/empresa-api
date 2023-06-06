<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\DeliveryZone;
use Illuminate\Http\Request;

class DeliveryZoneController extends Controller
{

    public function index() {
        $models = DeliveryZone::where('user_id', $this->userId())
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        $model = DeliveryZone::create([
            // 'num'                   => $this->num('delivery_zones'),
            'name'                  => $request->name,
            'description'           => $request->description,
            'price'                 => $request->price,
            'user_id'               => $this->userId(),
        ]);
        $this->sendAddModelNotification('delivery_zone', $model->id);
        return response()->json(['model' => $this->fullModel('DeliveryZone', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('DeliveryZone', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = DeliveryZone::find($id);
        $model->name                = $request->name;
        $model->description         = $request->description;
        $model->price               = $request->price;
        $model->save();
        $this->sendAddModelNotification('delivery_zone', $model->id);
        return response()->json(['model' => $this->fullModel('DeliveryZone', $model->id)], 200);
    }

    public function destroy($id) {
        $model = DeliveryZone::find($id);
        $model->delete();
        ImageController::deleteModelImages($model);
        $this->sendDeleteModelNotification('delivery_zone', $model->id);
        return response(null);
    }
}
