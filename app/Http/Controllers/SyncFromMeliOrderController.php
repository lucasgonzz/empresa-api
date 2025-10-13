<?php

namespace App\Http\Controllers;

use App\Jobs\SyncFromMeliOrderJob;
use App\Models\SyncFromMeliOrder;
use Illuminate\Http\Request;

class SyncFromMeliOrderController extends Controller
{
    // FROM DATES
    public function index($from_date = null, $until_date = null) {
        $models = SyncFromMeliOrder::where('user_id', $this->userId())
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
        $model = SyncFromMeliOrder::create([
            'user_id'               => $this->userId(),
        ]);

        dispatch(new SyncFromMeliOrderJob($model->id));

        return response()->json(['model' => $this->fullModel('SyncFromMeliOrder', $model->id)], 201);
    }  

    public function destroy($id) {
        $model = SyncFromMeliOrder::find($id);
        ImageController::deleteModelImages($model);
        $model->delete();
        $this->sendDeleteModelNotification('SyncFromMeliOrder', $model->id);
        return response(null);
    }
}
