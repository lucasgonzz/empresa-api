<?php

namespace App\Http\Controllers\CommonLaravel;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\Controller;
use App\Models\PermissionEmpresa;
use Illuminate\Http\Request;

class PermissionController extends Controller
{
    function index() {
        $models = PermissionEmpresa::all();
        return response()->json(['models' => $models], 200);
    }
}
