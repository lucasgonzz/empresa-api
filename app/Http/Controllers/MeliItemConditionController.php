<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\MeliItemCondition;
use Illuminate\Http\Request;

class MeliItemConditionController extends Controller
{

    public function index() {
        $models = MeliItemCondition::orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }
}
