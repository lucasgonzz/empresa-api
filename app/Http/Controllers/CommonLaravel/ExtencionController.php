<?php

namespace App\Http\Controllers\CommonLaravel;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Http\Controllers\Controller;
use App\Models\ExtencionEmpresa;
use Illuminate\Http\Request;

class ExtencionController extends Controller
{

    public function index() {
        $models = ExtencionEmpresa::orderBy('name', 'ASC')
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    
}
