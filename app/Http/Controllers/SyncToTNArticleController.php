<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\SyncToTNArticle;
use Illuminate\Http\Request;

class SyncToTNArticleController extends Controller
{

    // FROM DATES
    public function index($from_date = null, $until_date = null) {
        $models = SyncToTNArticle::where('user_id', $this->userId())
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

    public function destroy($id) {
        $model = SyncToTNArticle::find($id);
        ImageController::deleteModelImages($model);
        $model->delete();
        return response(null);
    }
}
