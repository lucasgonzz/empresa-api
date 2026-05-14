<?php

namespace App\Http\Controllers;

use App\Models\Platform;

/**
 * Listado de plataformas de integración (catálogo global para el ABM de conectores).
 */
class PlatformController extends Controller
{
    /**
     * Lista todas las plataformas ordenadas por nombre (sin filtrar por usuario).
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index()
    {
        $models = Platform::query()
            ->orderBy('name')
            ->withAll()
            ->get();

        return response()->json(['models' => $models], 200);
    }
}
