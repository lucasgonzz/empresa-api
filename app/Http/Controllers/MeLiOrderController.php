<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Http\Controllers\Helpers\Order\CreateSaleOrderHelper;
use App\Models\MeliOrder;
use Illuminate\Http\Request;

class MeLiOrderController extends Controller
{

    public function index($from_date = null, $until_date = null) {
        $models = MeliOrder::where('user_id', $this->userId())
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
        $model = MeliOrder::create([
            'num'                   => $this->num('MeliOrder'),
            'name'                  => $request->name,
            'user_id'               => $this->userId(),
        ]);
        $this->sendAddModelNotification('MeliOrder', $model->id);
        return response()->json(['model' => $this->fullModel('MeliOrder', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('MeliOrder', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = MeliOrder::find($id);
        $model->name                = $request->name;
        $model->save();
        $this->sendAddModelNotification('MeliOrder', $model->id);
        return response()->json(['model' => $this->fullModel('MeliOrder', $model->id)], 200);
    }

    public function destroy($id) {
        $model = MeliOrder::find($id);
        ImageController::deleteModelImages($model);
        $model->delete();
        $this->sendDeleteModelNotification('MeliOrder', $model->id);
        return response(null);
    }

    function create_sale($id) {
        
        $meli_order = MeliOrder::find($id);

        CreateSaleOrderHelper::save_sale($meli_order, $this, false, true);

        return response()->json(['model' => $this->fullModel('MeliOrder', $id)], 200);

    }
}
