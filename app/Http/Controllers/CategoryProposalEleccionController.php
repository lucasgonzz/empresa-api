<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * Elegir un sistema de categorías y volver atrás (misión categorizacion-tres-modelos, 5/10/2026).
 * Contrato B del plan, §6.4 y §6.5. Solo para el dueño o el acceso maestro (403 `solo_el_dueno`).
 *
 * Solo traduce a HTTP: la lógica vive en CategoryProposalAplicarHelper.
 */
class CategoryProposalEleccionController extends Controller
{
    /**
     * POST category-proposal-runs/{id}/elegir — aplica el sistema elegido (sincrónico, una transacción).
     *
     * 🔴 ESQUELETO DE LA BASE: lo implementa el constructor al que le toca (plan §7.1).
     *
     * @param  \Illuminate\Http\Request $request
     * @param  int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function elegir(Request $request, $id)
    {
        return response()->json([
            'error'   => 'no_implementado',
            'message' => 'Pendiente de construir (esqueleto de la base).',
        ], 501);
    }

    /**
     * POST category-proposal-runs/{id}/volver-atras — deshace la elección si nadie tocó nada.
     *
     * 🔴 ESQUELETO DE LA BASE: lo implementa el constructor al que le toca (plan §7.1).
     *
     * @param  \Illuminate\Http\Request $request
     * @param  int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function volver_atras(Request $request, $id)
    {
        return response()->json([
            'error'   => 'no_implementado',
            'message' => 'Pendiente de construir (esqueleto de la base).',
        ], 501);
    }
}
