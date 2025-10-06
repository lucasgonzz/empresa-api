<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\MeliBuyingMode;
use Illuminate\Http\Request;

class MeliBuyingModeController extends Controller
{

    public function index() {
        $models = MeliBuyingMode::orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }
}
