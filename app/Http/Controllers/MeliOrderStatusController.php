<?php

namespace App\Http\Controllers;

use App\Models\MeliOrderStatus;

/**
 * Listado de estados internos para pedidos Mercado Libre (formularios / selects).
 */
class MeliOrderStatusController extends Controller
{
    /**
     * Devuelve todos los estados ordenados por id.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index()
    {
        $models = MeliOrderStatus::orderBy('id')->get();

        return response()->json(['models' => $models], 200);
    }
}
