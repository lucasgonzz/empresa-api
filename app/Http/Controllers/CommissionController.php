<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\Commission;
use Illuminate\Http\Request;

class CommissionController extends Controller
{

    public function index() {
        $models = Commission::where('user_id', $this->userId())
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        $model = Commission::create([
            'num'                   => $this->num('commissions'),
            'from'                  => $request->from,
            'until'                 => $request->until,
            'sale_type_id'          => $request->sale_type_id,
            'amount'                => $request->amount,
            'user_id'               => $this->userId(),
        ]);
        $this->sendAddModelNotification('Commission', $model->id);
        return response()->json(['model' => $this->fullModel('Commission', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('Commission', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = Commission::find($id);
        $model->from                  = $request->from;
        $model->until                 = $request->until;
        $model->sale_type_id          = $request->sale_type_id;
        $model->amount                = $request->amount;
        $model->save();
        $this->sendAddModelNotification('Commission', $model->id);
        return response()->json(['model' => $this->fullModel('Commission', $model->id)], 200);
    }

    public function destroy($id) {
        $model = Commission::find($id);
        ImageController::deleteModelImages($model);
        $model->delete();
        $this->sendDeleteModelNotification('Commission', $model->id);
        return response(null);
    }
}
