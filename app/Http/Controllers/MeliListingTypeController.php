<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\MeliListingType;
use Illuminate\Http\Request;

class MeliListingTypeController extends Controller
{

    public function index() {
        $models = MeliListingType::orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }
}
