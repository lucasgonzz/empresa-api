<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\FilterHistory;
use Illuminate\Http\Request;

class FilterHistoryController extends Controller
{

    // FROM DATES
    public function index($from_date = null, $until_date = null) {
        $models = FilterHistory::where('user_id', $this->userId())
                        ->orderBy('id', 'DESC')
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

    
}
