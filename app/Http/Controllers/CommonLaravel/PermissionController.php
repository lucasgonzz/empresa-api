<?php

namespace App\Http\Controllers\CommonLaravel;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\PermisosCatalogoHelper;
use App\Models\PermissionEmpresa;
use Illuminate\Http\Request;

class PermissionController extends Controller
{
    function index() {
        // El orden (grupos de arriba hacia abajo y, dentro de cada uno, entrar -> crear -> editar ->
        // eliminar) lo fija el catalogo: sin esto salia por id, distinto en cada base.
        $models = PermisosCatalogoHelper::ordenar(PermissionEmpresa::all());
        return response()->json(['models' => $models], 200);
    }
}
