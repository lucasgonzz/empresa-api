<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * Aprobar o rechazar los ítems dudosos de la propuesta elegida (misión categorizacion-tres-modelos,
 * 5/10/2026). Contrato B del plan, §6.7. Solo para el dueño o el acceso maestro (403 `solo_el_dueno`).
 *
 * Solo traduce a HTTP: la lógica vive en CategoryProposalAplicarHelper.
 */
class CategoryProposalItemController extends Controller
{
    /**
     * POST category-proposal-items/{id}/aprobar — el artículo recibe la categoría sugerida.
     *
     * 🔴 ESQUELETO DE LA BASE: lo implementa el constructor al que le toca (plan §7.1).
     *
     * @param  \Illuminate\Http\Request $request
     * @param  int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function aprobar(Request $request, $id)
    {
        return response()->json([
            'error'   => 'no_implementado',
            'message' => 'Pendiente de construir (esqueleto de la base).',
        ], 501);
    }

    /**
     * POST category-proposal-items/{id}/rechazar — el artículo sigue sin categoría.
     *
     * 🔴 ESQUELETO DE LA BASE: lo implementa el constructor al que le toca (plan §7.1).
     *
     * @param  \Illuminate\Http\Request $request
     * @param  int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function rechazar(Request $request, $id)
    {
        return response()->json([
            'error'   => 'no_implementado',
            'message' => 'Pendiente de construir (esqueleto de la base).',
        ], 501);
    }

    /**
     * POST category-proposal-items/aprobar-varios {ids} — hasta 500 por pedido.
     *
     * 🔴 ESQUELETO DE LA BASE: lo implementa el constructor al que le toca (plan §7.1).
     *
     * @param  \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function aprobar_varios(Request $request)
    {
        return response()->json([
            'error'   => 'no_implementado',
            'message' => 'Pendiente de construir (esqueleto de la base).',
        ], 501);
    }

    /**
     * POST category-proposal-items/rechazar-varios {ids} — hasta 500 por pedido.
     *
     * 🔴 ESQUELETO DE LA BASE: lo implementa el constructor al que le toca (plan §7.1).
     *
     * @param  \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function rechazar_varios(Request $request)
    {
        return response()->json([
            'error'   => 'no_implementado',
            'message' => 'Pendiente de construir (esqueleto de la base).',
        ], 501);
    }
}
