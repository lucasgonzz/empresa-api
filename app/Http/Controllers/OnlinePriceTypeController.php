<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\OnlinePriceType;
use Illuminate\Http\Request;

class OnlinePriceTypeController extends Controller
{

    public function index() {
        $models = OnlinePriceType::orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

}
