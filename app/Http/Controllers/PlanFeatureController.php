<?php

namespace App\Http\Controllers;

use App\Models\PlanFeature;
use Illuminate\Http\Request;

class PlanFeatureController extends Controller
{
    function index() {
        $models = PlanFeature::all();
        return response()->json(['models' => $models], 200);
    }
}
