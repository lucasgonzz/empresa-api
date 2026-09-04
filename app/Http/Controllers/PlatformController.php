<?php

namespace App\Http\Controllers;

use App\Models\Platform;

/**
 * Listado de plataformas de integración (catálogo global para el ABM de conectores).
 */
class PlatformController extends Controller
{
    /**
     * Lista las plataformas conectables desde el ABM de conectores, ordenadas por nombre (sin
     * filtrar por usuario).
     *
     * El filtro es del lado del backend a propósito, y no en el select de la SPA: así no depende
     * de que el frontend se acuerde de excluir Mercado Pago, que se conecta por su propia
     * pantalla. Ver `Platform::SLUGS_CONECTABLES_POR_ABM`.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index()
    {
        $models = Platform::query()
            ->conectablesPorAbm()
            ->orderBy('name')
            ->withAll()
            ->get();

        return response()->json(['models' => $models], 200);
    }
}
