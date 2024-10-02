<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Models\AfipTipoComprobante;
use Illuminate\Http\Request;

class AfipTipoComprobanteController extends Controller
{

    public function index() {
        $models = AfipTipoComprobante::orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    
}
