<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\Cupon;
use Illuminate\Http\Request;

class CuponController extends Controller
{

    public function index() {
        $models = Cupon::where('user_id', $this->userId())
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        $model = Cupon::create([
            'num'                   => $this->num('cupons'),
            'amount'                => $request->amount,
            'percentage'            => $request->percentage,
            'min_amount'            => $request->min_amount,
            'code'                  => $request->code,
            'type'                  => 'normal',
            'user_id'               => $this->userId(),
        ]);
        $this->sendAddModelNotification('cupon', $model->id);
        return response()->json(['model' => $this->fullModel('Cupon', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('Cupon', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = Cupon::find($id);
        $model->amount                = $request->amount;
        $model->percentage            = $request->percentage;
        $model->min_amount            = $request->min_amount;
        $model->code                  = $request->code;
        $model->save();
        $this->sendAddModelNotification('cupon', $model->id);
        return response()->json(['model' => $this->fullModel('Cupon', $model->id)], 200);
    }

    public function destroy($id) {
        $model = Cupon::find($id);
        $model->delete();
        ImageController::deleteModelImages($model);
        $this->sendDeleteModelNotification('cupon', $model->id);
        return response(null);
    }
}
