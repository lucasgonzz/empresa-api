<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\TiendaNubeOrderStatus;
use Illuminate\Http\Request;

class TiendaNubeOrderStatusController extends Controller
{

    public function index() {
        $models = TiendaNubeOrderStatus::orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }
   
}
