<?php

namespace App\Http\Controllers;

use App\Models\Check;
use App\Models\CurrentAcount;
use Illuminate\Http\Request;

class CheckController extends Controller
{
    function index() {
        $models = CurrentAcount::where('user_id', $this->userId())
                            ->whereNotNull('num')
                            ->orderBy('created_at', 'DESC')
                            ->get();
        return response()->json(['models' => $models], 200);
    }
}
