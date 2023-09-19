<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use Illuminate\Http\Request;

class PlanController extends Controller
{
    function index() {
        $models = Plan::where('official', 1)
                        ->orderBy('id', 'ASC')
                        ->with('plan_features')
                        ->get();
        return response()->json(['models' => $models], 200);
    }
}
