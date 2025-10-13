<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Jobs\SyncFromMeliArticlesJob;
use App\Models\SyncFromMeliArticle;
use Illuminate\Http\Request;

class SyncFromMeliArticleController extends Controller
{


    // FROM DATES
    public function index($from_date = null, $until_date = null) {
        $models = SyncFromMeliArticle::where('user_id', $this->userId())
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
        $model = SyncFromMeliArticle::create([
            'user_id'               => $this->userId(),
        ]);

        dispatch(new SyncFromMeliArticlesJob($model->id));

        return response()->json(['model' => $this->fullModel('SyncFromMeliArticle', $model->id)], 201);
    }  

    public function destroy($id) {
        $model = SyncFromMeliArticle::find($id);
        ImageController::deleteModelImages($model);
        $model->delete();
        $this->sendDeleteModelNotification('SyncFromMeliArticle', $model->id);
        return response(null);
    }
}
