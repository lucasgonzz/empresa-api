<?php

namespace App\Http\Controllers;

use App\Models\ImportHistory;
use Illuminate\Http\Request;

class ImportHistoryController extends Controller
{
    function index($model_name) {
        $models = ImportHistory::where('user_id', $this->userId())
                                ->where('model_name', $model_name)
                                ->orderBy('id', 'DESC')
                                ->get();
        return response()->json(['models' => $models], 200);
    }
}
