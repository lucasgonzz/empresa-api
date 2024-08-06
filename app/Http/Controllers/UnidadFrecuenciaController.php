<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\UnidadFrecuencia;
use Illuminate\Http\Request;

class UnidadFrecuenciaController extends Controller
{

    public function index() {
        $models = UnidadFrecuencia::orderBy('created_at', 'DESC')
                                ->get();
        return response()->json(['models' => $models], 200);
    }

}
