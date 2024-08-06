<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Http\Controllers\Helpers\PendingCompletedHelper;
use App\Models\PendingCompleted;
use Carbon\Carbon;
use Illuminate\Http\Request;

class PendingCompletedController extends Controller
{

    public function index($from_date = null, $until_date = null) {
        $models = PendingCompleted::where('user_id', $this->userId())
                        ->orderBy('created_at', 'DESC')
                        ->whereDate('created_at', '>=', $from_date)
                        ->whereDate('created_at', '<=', $until_date)
                        ->withAll()
                        ->get();
        return response()->json(['models' => $models], 200);
    }

    public function store(Request $request) {
        
        $model = PendingCompleted::create([
            'pending_id'            => $request->id,
            'detalle'               => $request->detalle,
            'notas'                 => $request->notas,
            'fecha_realizacion'     => Carbon::parse($request->fecha_realizacion)->format('Y-m-d H:i:s'),
            'fecha_realizada'       => Carbon::now(),
            'user_id'               => $this->userId(),
        ]);


        PendingCompletedHelper::set_pending_completada($model);

        PendingCompletedHelper::check_expense_concept($model);

        $this->sendAddModelNotification('PendingCompleted', $model->id);
        return response()->json(['model' => $this->fullModel('PendingCompleted', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('PendingCompleted', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = PendingCompleted::find($id);
        $model->name                = $request->name;
        $model->save();
        $this->sendAddModelNotification('PendingCompleted', $model->id);
        return response()->json(['model' => $this->fullModel('PendingCompleted', $model->id)], 200);
    }

    public function destroy($id) {
        $model = PendingCompleted::find($id);
        ImageController::deleteModelImages($model);
        $model->delete();
        $this->sendDeleteModelNotification('PendingCompleted', $model->id);
        return response(null);
    }
}
