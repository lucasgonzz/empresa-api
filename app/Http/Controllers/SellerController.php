<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\Seller;
use Illuminate\Http\Request;

class SellerController extends Controller
{

    public function index() {
        $models = Seller::where('user_id', $this->userId())
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        $model = Seller::create([
            'num'                   => $this->num('sellers'),
            'name'                  => $request->name,
            'commision'             => $request->commision,
            'seller_id'             => $request->seller_id,
            'user_id'               => $this->userId(),
        ]);
        $this->sendAddModelNotification('Seller', $model->id);
        return response()->json(['model' => $this->fullModel('Seller', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('Seller', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = Seller::find($id);
        $model->name                = $request->name;
        $model->commision           = $request->commision;
        $model->seller_id           = $request->seller_id;
        $model->save();
        $this->sendAddModelNotification('Seller', $model->id);
        return response()->json(['model' => $this->fullModel('Seller', $model->id)], 200);
    }

    public function destroy($id) {
        $model = Seller::find($id);
        $model->delete();
        ImageController::deleteModelImages($model);
        $this->sendDeleteModelNotification('Seller', $model->id);
        return response(null);
    }
}
