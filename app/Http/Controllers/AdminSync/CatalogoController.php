<?php

namespace App\Http\Controllers\AdminSync;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Lo que la skill /categorizar lee del catálogo de un cliente (misión categorizacion-tres-modelos,
 * 5/10/2026). Contrato A del plan, §5.1 y §5.2. Dueño por `AsistenteCanalHelper::dueno()`; la clave
 * `X-Admin-Api-Key` se exige SIEMPRE, adentro del controlador (trait ClaveEstrictaDeAdmin).
 */
class CatalogoController extends Controller
{
    /**
     * GET admin-sync/catalogo/resumen — hechos del cliente para diseñar los sistemas.
     *
     * 🔴 ESQUELETO DE LA BASE: lo implementa el constructor al que le toca (plan §7.1).
     *
     * @param  \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function resumen(Request $request)
    {
        return response()->json([
            'error'   => 'no_implementado',
            'message' => 'Pendiente de construir (esqueleto de la base).',
        ], 501);
    }

    /**
     * GET admin-sync/catalogo/articulos?despues_de=&limite= — el inventario por cursor de id.
     *
     * 🔴 ESQUELETO DE LA BASE: lo implementa el constructor al que le toca (plan §7.1).
     *
     * @param  \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function articulos(Request $request)
    {
        return response()->json([
            'error'   => 'no_implementado',
            'message' => 'Pendiente de construir (esqueleto de la base).',
        ], 501);
    }
}
