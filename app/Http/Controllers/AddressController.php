<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\Address;
use Illuminate\Http\Request;

class AddressController extends Controller
{

    public function index() {
        $models = Address::where('user_id', $this->userId())
                            ->orderBy('created_at', 'ASC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        $model = Address::create([
            'num'                   => $this->num('addresses'),
            'street'                => $request->street,
            'street_number'         => $request->street_number,
            'city'                  => $request->city,
            'province'              => $request->province,
            'default_address'       => $request->default_address,
            'user_id'               => $this->userId(),
        ]);
        $this->sendAddModelNotification('Address', $model->id);
        return response()->json(['model' => $this->fullModel('Address', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('Address', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = Address::find($id);
        $model->street                = $request->street;
        $model->street_number         = $request->street_number;
        $model->city                  = $request->city;
        $model->province              = $request->province;
        $model->default_address       = $request->default_address;
        $model->save();
        $this->sendAddModelNotification('Address', $model->id);
        return response()->json(['model' => $this->fullModel('Address', $model->id)], 200);
    }

    public function destroy($id) {
        $model = Address::find($id);

        $model->articles()->detach();

        $model->delete();
        ImageController::deleteModelImages($model);
        $this->sendDeleteModelNotification('Address', $model->id);
        return response(null);
    }
}
